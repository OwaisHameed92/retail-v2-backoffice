<?php

namespace App\Domain\Reporting\Support;

use App\Domain\Reporting\ReportTables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The queue of shop-days to rebuild (`rpt_dirty_days`, DASHBOARD.md §4.7–4.8). Marking a day again gives it a new
 * token; a rebuild removes only the tokens it read, so a push that lands while its day is being rebuilt keeps the
 * day queued for the next pass.
 */
final class DirtyDays
{
    /**
     * @param  array<string, array{branch: string, day: string}>  $days  keyed "branch|day"
     */
    public static function mark(string $companyId, array $days): void
    {
        if ($days === []) {
            return;
        }

        $now = now('UTC')->format('Y-m-d H:i:s');
        $rows = [];

        foreach ($days as $day) {
            $rows[] = [
                'company_id' => $companyId,
                'branch_id' => $day['branch'],
                'trading_day' => $day['day'],
                'token' => (string) Str::ulid(),
                'marked_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table(ReportTables::DIRTY_DAYS)->upsert($chunk, ReportTables::SCOPE, ['token', 'marked_at']);
        }
    }

    /**
     * Marks the (shop, trading day) of the given sales dirty. Call it before deleting sales outside the push path
     * (demo clean-up), so the days they leave behind are rebuilt and no `rpt_*` row outlives its raw rows.
     *
     * @param  list<string>  $saleIds
     * @return string|null the oldest trading day marked
     */
    public static function markSales(string $companyId, array $saleIds): ?string
    {
        $days = [];

        foreach (array_chunk($saleIds, 500) as $chunk) {
            $rows = DB::table('sales')->where('company_id', $companyId)->whereIn('id', $chunk)
                ->whereNotNull('branch_id')->whereNotNull('trading_day')->distinct()->get(['branch_id', 'trading_day']);

            foreach ($rows as $row) {
                $day = substr((string) $row->trading_day, 0, 10);
                $days[$row->branch_id.'|'.$day] = ['branch' => (string) $row->branch_id, 'day' => $day];
            }
        }

        self::mark($companyId, $days);

        return $days === [] ? null : min(array_column($days, 'day'));
    }

    /**
     * The oldest dirty days of a company.
     *
     * @return list<object{branch_id: string, trading_day: string, token: string}>
     */
    public static function take(string $companyId, int $limit): array
    {
        /** @var list<object{branch_id: string, trading_day: string, token: string}> */
        return DB::table(ReportTables::DIRTY_DAYS)->where('company_id', $companyId)
            ->orderBy('branch_id')->orderBy('trading_day')->limit($limit)
            ->get(['branch_id', 'trading_day', 'token'])->all();
    }

    /**
     * @param  list<string>  $tokens
     */
    public static function clear(string $companyId, array $tokens): void
    {
        foreach (array_chunk($tokens, 500) as $chunk) {
            DB::table(ReportTables::DIRTY_DAYS)->where('company_id', $companyId)->whereIn('token', $chunk)->delete();
        }
    }

    /**
     * Companies with days marked before the given time (for the sweep).
     *
     * @return list<string>
     */
    public static function companiesMarkedBefore(string $utc): array
    {
        return DB::table(ReportTables::DIRTY_DAYS)->where('marked_at', '<', $utc)->distinct()->orderBy('company_id')
            ->pluck('company_id')->map(fn ($id) => (string) $id)->all();
    }
}
