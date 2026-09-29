<?php

namespace App\Domain\TillHealth\Actions;

use App\Domain\Shared\Support\Ulid;
use App\Domain\TillHealth\Data\BranchHealthRow;
use App\Domain\TillHealth\Data\TillHealthRow;
use App\Domain\TillHealth\Queries\HealthSources;
use App\Domain\TillHealth\Support\HealthThresholds;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds `till_health` and `branch_health` (module 2.7) and raises / clears the health alerts, 200 businesses at
 * a time: per chunk four reads, two upserts, two deletes and the alert queries, so 1,000 businesses take a few
 * seconds. Rows of tills or shops that stopped being monitored (deactivated, deleted, business cancelled) are
 * removed. Run by `till-health:refresh` every 5 minutes; idempotent.
 */
class RefreshTillHealth
{
    public const CHUNK = 200;

    private const UPSERT_ROWS = 500;

    public function __construct(private readonly SyncTillHealthAlerts $alerts) {}

    /**
     * @param  list<string>|null  $companyIds  Only these businesses (tests, a single tenant); null = all.
     * @return array{companies: int, tills: int, branches: int, raised: int, resolved: int}
     */
    public function handle(?CarbonImmutable $now = null, ?array $companyIds = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc()->startOfSecond();
        $thresholds = HealthThresholds::fromConfig();
        $totals = ['companies' => 0, 'tills' => 0, 'branches' => 0, 'raised' => 0, 'resolved' => 0];

        DB::table('companies')
            ->when($companyIds !== null, fn ($query) => $query->whereIn('id', $companyIds ?? []))
            ->select('id')
            ->chunkById(self::CHUNK, function ($companies) use ($now, $thresholds, &$totals) {
                $ids = $companies->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
                $result = HealthSources::evaluate($ids, $now, $thresholds);

                DB::transaction(function () use ($ids, $result, $now) {
                    $this->store('till_health', 'register_id', array_map(fn (TillHealthRow $row) => $row->columns($now), $result['tills']), $ids, $now);
                    $this->store('branch_health', 'branch_id', array_map(fn (BranchHealthRow $row) => $row->columns($now), $result['branches']), $ids, $now);
                });

                $alerts = $this->alerts->handle($ids, $result, $now, $thresholds);

                $totals['companies'] += count($ids);
                $totals['tills'] += count($result['tills']);
                $totals['branches'] += count($result['branches']);
                $totals['raised'] += $alerts['raised'];
                $totals['resolved'] += $alerts['resolved'];
            }, 'id');

        return $totals;
    }

    /**
     * Upsert this refresh's rows, then drop the chunk's rows it did not touch.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $companyIds
     */
    private function store(string $table, string $key, array $rows, array $companyIds, CarbonImmutable $now): void
    {
        $stamp = TillHealthRow::sql($now);

        foreach (array_chunk($rows, self::UPSERT_ROWS) as $chunk) {
            $values = array_map(fn (array $row) => ['id' => Ulid::new(), ...$row, 'created_at' => $stamp, 'updated_at' => $stamp], $chunk);
            $update = array_values(array_diff(array_keys($values[0]), ['id', 'created_at', $key]));

            DB::table($table)->upsert($values, [$key], $update);
        }

        DB::table($table)->whereIn('company_id', $companyIds)->where('checked_at', '<', $stamp)->delete();
    }
}
