<?php

namespace App\Domain\Purchasing\Reorder\Sources;

use App\Domain\Reporting\Support\TradingDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Supplier lead times (module 6.4) from delivery history: days from the order being sent (its `sentAt`, else when it
 * was made, London date) to the delivery booked in against it (`GoodsReceipt.receivedDate`, posted). The median of
 * the latest `lead_time_samples` deliveries at this shop; with fewer than `lead_time_min_samples` there, the same
 * across every shop; then the supplier's own lead time; then `reorder.default_lead_days`. Rounded up to whole days.
 *
 * @phpstan-type Lead array{days: int, basis: 'shop'|'business'|'supplier'|'default', samples: int}
 */
final class LeadTimes
{
    /**
     * @param  array<string, int|null>  $suppliers  supplier id → the supplier's own lead time (or null)
     * @return array<string, Lead>
     */
    public static function forShop(string $companyId, string $shopId, array $suppliers): array
    {
        if ($suppliers === []) {
            return [];
        }

        $rows = DB::table('goods_receipts as gr')
            ->join('purchase_orders as po', fn ($j) => $j->on('po.id', '=', 'gr.purchase_order_id')->where('po.company_id', $companyId))
            ->where('gr.company_id', $companyId)->whereNull('gr.deleted_at')->where('gr.status', 'posted')->whereNotNull('gr.received_date')
            ->whereIn('po.supplier_id', array_keys($suppliers))
            ->orderByDesc('gr.received_date')->limit(5000)
            ->get(['gr.branch_id', 'po.supplier_id', 'gr.received_date', 'po.sent_at', 'po.created_at']);

        [$shop, $all] = [[], []];

        foreach ($rows as $row) {
            $sent = $row->sent_at ?? $row->created_at;

            if ($sent === null) {
                continue;
            }

            $from = CarbonImmutable::parse((string) $sent, 'UTC')->setTimezone(TradingDay::timezone())->startOfDay();
            $days = (int) $from->diffInDays(CarbonImmutable::parse(substr((string) $row->received_date, 0, 10), TradingDay::timezone()), false);

            if ($days < 0 || $days > 60) {
                continue;
            }

            $supplier = (string) $row->supplier_id;
            $all[$supplier][] = $days;

            if ((string) $row->branch_id === $shopId) {
                $shop[$supplier][] = $days;
            }
        }

        $samples = max(1, (int) config('reorder.lead_time_samples', 10));
        $minimum = max(1, (int) config('reorder.lead_time_min_samples', 2));
        $out = [];

        foreach ($suppliers as $supplier => $ownDays) {
            $here = array_slice($shop[$supplier] ?? [], 0, $samples);
            $everywhere = array_slice($all[$supplier] ?? [], 0, $samples);

            $out[$supplier] = match (true) {
                count($here) >= $minimum => ['days' => self::median($here), 'basis' => 'shop', 'samples' => count($here)],
                count($everywhere) >= $minimum => ['days' => self::median($everywhere), 'basis' => 'business', 'samples' => count($everywhere)],
                $ownDays !== null && $ownDays > 0 => ['days' => $ownDays, 'basis' => 'supplier', 'samples' => 0],
                default => ['days' => max(0, (int) config('reorder.default_lead_days', 2)), 'basis' => 'default', 'samples' => 0],
            };
        }

        return $out;
    }

    /**
     * @param  list<int>  $values
     */
    public static function median(array $values): int
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : (int) ceil(($values[$mid - 1] + $values[$mid]) / 2);
    }
}
