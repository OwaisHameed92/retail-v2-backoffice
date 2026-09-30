<?php

namespace App\Domain\Reporting\Actions;

use App\Domain\Reporting\Build\ReportRowBuilder;
use App\Domain\Reporting\ReportTables;
use App\Domain\Reporting\Support\ReportDayPlan;
use App\Domain\Reporting\Support\Units;
use App\Domain\Shared\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Proves the incrementally maintained `rpt_*` rows equal a full rebuild (`reports:check`): for every shop-day in
 * range, the rows the raw till rows give now are worked out in memory (nothing written) and compared with the stored
 * ones, column by column. Also counts sales that have no trading day stamped yet. With `$fix`, a shop-day that
 * differs is rebuilt.
 */
final class CheckReports
{
    public function __construct(private readonly RebuildReportDays $rebuild) {}

    /**
     * @param  list<string>  $companies  empty = every business
     * @return array{days: int, mismatches: list<string>, unstamped: int, fixed: int}
     */
    public function handle(array $companies = [], ?string $from = null, ?string $to = null, bool $fix = false): array
    {
        $result = ['days' => 0, 'mismatches' => [], 'unstamped' => 0, 'fixed' => 0];
        $perChunk = max(1, (int) config('reporting.days_per_rebuild', 7));

        foreach (ReportDayPlan::companies($companies) as $companyId) {
            $result['unstamped'] += DB::table('sales')->where('company_id', $companyId)->whereNull('trading_day')
                ->where(fn ($q) => $q->where(fn ($c) => $c->where('status', 'completed')->whereNotNull('completed_at'))->orWhere('status', 'voided'))
                ->count();

            foreach (ReportDayPlan::chunks($companyId, $from, $to, $perChunk) as $chunk) {
                $result['days'] += count($chunk['days']);
                $found = $this->compare($companyId, $chunk['branch'], $chunk['days']);

                if ($found !== []) {
                    array_push($result['mismatches'], ...$found);

                    if ($fix) {
                        $this->rebuild->handle($companyId, $chunk['branch'], $chunk['days']);
                        $result['fixed'] += count($chunk['days']);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $days
     * @return list<string>
     */
    public function compare(string $companyId, string $branchId, array $days): array
    {
        $expected = ReportRowBuilder::build($companyId, $branchId, $days, '');
        $mismatches = [];

        foreach (ReportTables::names() as $table) {
            $want = [];

            foreach ($expected[$table] as $row) {
                $want[ReportTables::rowKey($table, $row)] = self::canonical($table, $row);
            }

            $have = [];

            foreach (DB::table($table)->where('company_id', $companyId)->where('branch_id', $branchId)->whereIn('trading_day', $days)->get() as $stored) {
                $row = self::canonical($table, (array) $stored);
                $have[ReportTables::rowKey($table, $row)] = $row;
            }

            foreach (array_unique([...array_keys($want), ...array_keys($have)]) as $key) {
                $a = $want[$key] ?? null;
                $b = $have[$key] ?? null;

                if ($a === null || $b === null) {
                    $mismatches[] = "{$table} {$key}: ".($a === null ? 'stored but not in the raw rows' : 'missing');
                } elseif ($a !== $b) {
                    $columns = array_keys(array_filter($a, fn ($value, $column) => ($b[$column] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
                    $mismatches[] = "{$table} {$key}: ".implode(', ', array_map(fn ($c) => "{$c} {$b[$c]} ≠ {$a[$c]}", $columns));
                }
            }
        }

        return $mismatches;
    }

    /**
     * A row with every value as a comparable string (decimals at their scale, whatever the driver returned).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    public static function canonical(string $table, array $row): array
    {
        $def = ReportTables::TABLES[$table];
        $out = [];

        foreach ([...ReportTables::SCOPE, ...$def['keys']] as $column) {
            $value = $row[$column] ?? '';
            $out[$column] = match ($column) {
                'trading_day' => substr((string) $value, 0, 10),
                'percentage' => Money::normalise($value === '' ? 0 : $value, 4),
                default => (string) $value,
            };
        }

        foreach ($def['decimals'] as $column => $scale) {
            $out[$column] = Units::decimal(Units::fromDecimal($row[$column] ?? 0, $scale), $scale);
        }

        foreach ($def['counts'] as $column) {
            $out[$column] = (string) (int) ($row[$column] ?? 0);
        }

        foreach ($def['labels'] as $column) {
            $out[$column] = (string) ($row[$column] ?? '');
        }

        return $out;
    }
}
