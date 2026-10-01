<?php

namespace App\Domain\Reporting\Actions;

use App\Domain\Reporting\Demo\DemoShop;
use App\Domain\Reporting\Demo\DemoShopDay;
use App\Domain\Reporting\ReportTables;
use App\Domain\Reporting\Support\DirtyDays;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Domain\TillData\Actions\ApplySyncChanges;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * `demo:sales` (module 3.2): realistic till sales for a demo business, stored through the real push path
 * (`ApplySyncChanges`, stream "demo-sales", so ids, trading days and the ledger are exactly as for a till), then the
 * business's `rpt_*` tables are rebuilt for the range. Never in production.
 *
 * Repeatable: ids and seqs come from the shop and date, so a second run stores nothing twice and only adds what is
 * new (today's later hours, new days). `fresh` first removes every demo sale of the business (found through the
 * ledger's "demo-sales" stream, never a till's own rows).
 */
final class GenerateDemoSales
{
    public function __construct(
        private readonly ApplySyncChanges $apply,
        private readonly DemoShopDay $shopDay,
        private readonly RebuildReports $rebuild,
        private readonly ProcessDirtyReportDays $dirty,
    ) {}

    /**
     * @param  (Closure(string, string, array{sales: int, refunds: int, voids: int}): void)|null  $progress  shop name, day, counts
     * @param  bool  $rebuild  false when the caller rebuilds the reporting tables itself (`demo:seed`)
     * @return array{shops: int, days: int, sales: int, refunds: int, voids: int, removed: int}
     */
    public function handle(Company $company, int $days, bool $fresh = false, ?CarbonImmutable $now = null, ?Closure $progress = null, bool $rebuild = true): array
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Demo sales are never generated in production.');
        }

        $now ??= CarbonImmutable::now();
        $today = TradingDay::today($now)->format('Y-m-d');
        $from = CarbonImmutable::parse($today, 'UTC')->subDays(max(1, $days) - 1)->toDateString();
        $totals = ['shops' => 0, 'days' => max(1, $days), 'sales' => 0, 'refunds' => 0, 'voids' => 0, 'removed' => 0];
        $rebuildFrom = $from;

        if ($fresh) {
            [$totals['removed'], $oldest] = $this->forget($company->getKey());
            $rebuildFrom = $oldest !== null && $oldest < $from ? $oldest : $from;
        }

        foreach ($this->shops($company) as [$branch, $shop]) {
            $totals['shops']++;

            // One transaction per shop: the push path nests its chunks as savepoints, so the whole shop is written
            // with one commit (minutes → seconds on SQLite) and its report jobs are queued once, after it.
            DB::transaction(function () use ($company, $branch, $shop, $from, $today, $now, $progress, &$totals) {
                foreach (TradingDay::range($from, $today) as $day) {
                    $built = $this->shopDay->build($shop, $day, $now);

                    if ($built['changes'] !== []) {
                        $rejected = $this->apply->handle($company, $branch, $built['changes'], DemoShopDay::STREAM)->firstRejection();

                        if ($rejected !== null) {
                            throw new RuntimeException("A demo row was refused: {$rejected->key} {$rejected->code} {$rejected->message}");
                        }
                    }

                    foreach (['sales', 'refunds', 'voids'] as $key) {
                        $totals[$key] += $built[$key];
                    }

                    if ($progress !== null) {
                        $progress($branch->name, $day, ['sales' => $built['sales'], 'refunds' => $built['refunds'], 'voids' => $built['voids']]);
                    }
                }
            });
        }

        if (! $rebuild) {
            return $totals;
        }

        $started = now('UTC')->format('Y-m-d H:i:s');
        $this->rebuild->handle([$company->getKey()], $rebuildFrom, $today);

        // The rebuild covered every day the pushes above marked; the queued job has nothing left to do.
        DB::table(ReportTables::DIRTY_DAYS)->where('company_id', $company->getKey())
            ->whereBetween('trading_day', [$rebuildFrom, $today])->where('marked_at', '<=', $started)->delete();
        // Anything still queued (e.g. days of removed sales outside the range) is rebuilt now, not left stale.
        $this->dirty->handle($company->getKey());

        return $totals;
    }

    /**
     * Active shops with active tills (main till first).
     *
     * @return list<array{0: Branch, 1: DemoShop}>
     */
    private function shops(Company $company): array
    {
        $shops = [];
        $branches = Branch::withoutCompanyScope()->where('company_id', $company->getKey())->where('is_active', true)->orderBy('code')->get();

        foreach ($branches as $branch) {
            $registers = Register::withoutCompanyScope()->where('branch_id', $branch->id)->where('is_active', true)
                ->orderByDesc('is_main_till')->orderBy('code')->get(['id', 'code'])
                ->map(fn (Register $r) => ['id' => (string) $r->id, 'code' => (string) $r->code])->values()->all();

            if ($registers !== []) {
                $shops[] = [$branch, new DemoShop($company->getKey(), $branch->id, $branch->code, $registers)];
            }
        }

        return $shops;
    }

    /**
     * Removes every demo sale of the business (and its lines, payments, VAT and ledger rows), marking their days dirty.
     *
     * @return array{0: int, 1: string|null} sales removed, their oldest trading day
     */
    private function forget(string $companyId): array
    {
        $ledger = DB::table('sync_applied_changes')->where('company_id', $companyId)->where('stream', DemoShopDay::STREAM);
        $saleIds = (clone $ledger)->where('entity', 'Sale')->pluck('entity_id')->map(fn ($id) => (string) $id)->all();
        $oldest = null;

        foreach (array_chunk($saleIds, 500) as $chunk) {
            // Mark the days first, so their rpt_* rows are rebuilt (emptied) and never outlive the raw rows.
            $day = DirtyDays::markSales($companyId, $chunk);
            $oldest = $day !== null && ($oldest === null || $day < $oldest) ? $day : $oldest;

            foreach (['sale_lines', 'sale_payments', 'sale_vats'] as $table) {
                DB::table($table)->where('company_id', $companyId)->whereIn('sale_id', $chunk)->delete();
            }

            DB::table('sales')->where('company_id', $companyId)->whereIn('id', $chunk)->delete();
        }

        $ledger->delete();

        return [count($saleIds), $oldest];
    }
}
