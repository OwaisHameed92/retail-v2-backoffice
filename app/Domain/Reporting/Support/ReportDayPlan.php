<?php

namespace App\Domain\Reporting\Support;

use App\Domain\Reporting\ReportTables;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which shop-days a full rebuild or check covers: every (shop, trading day) that has sales in the raw rows, plus
 * every one that has report rows or is queued dirty (so a day whose sales are gone is emptied), within an optional
 * date range, in chunks of a few days per shop.
 */
final class ReportDayPlan
{
    /**
     * Business ids, in id order.
     *
     * @param  list<string>  $only
     * @return list<string>
     */
    public static function companies(array $only = []): array
    {
        return DB::table('companies')->when($only !== [], fn (Builder $q) => $q->whereIn('id', $only))
            ->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /**
     * @return list<array{branch: string, days: list<string>}>
     */
    public static function chunks(string $companyId, ?string $from, ?string $to, int $daysPerChunk): array
    {
        $range = function (Builder $q) use ($from, $to) {
            $q->when($from !== null, fn (Builder $w) => $w->where('trading_day', '>=', $from))
                ->when($to !== null, fn (Builder $w) => $w->where('trading_day', '<=', $to));
        };

        $pairs = DB::table('sales')->where('company_id', $companyId)->whereNotNull('trading_day')->whereNotNull('branch_id')
            ->tap($range)->distinct()->select(['branch_id', 'trading_day'])
            ->union(DB::table(ReportTables::SALES_DAILY)->where('company_id', $companyId)->tap($range)->distinct()->select(['branch_id', 'trading_day']))
            ->union(DB::table(ReportTables::DIRTY_DAYS)->where('company_id', $companyId)->tap($range)->distinct()->select(['branch_id', 'trading_day']))
            ->get();

        $byBranch = [];

        foreach ($pairs as $pair) {
            $byBranch[(string) $pair->branch_id][substr((string) $pair->trading_day, 0, 10)] = true;
        }

        ksort($byBranch);
        $chunks = [];

        foreach ($byBranch as $branch => $days) {
            $days = array_keys($days);
            sort($days);

            foreach (array_chunk($days, max(1, $daysPerChunk)) as $chunk) {
                $chunks[] = ['branch' => (string) $branch, 'days' => $chunk];
            }
        }

        return $chunks;
    }
}
