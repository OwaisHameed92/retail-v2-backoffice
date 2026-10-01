<?php

namespace Tests\Feature\Purchasing;

use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Purchasing\PurchasingFixtures as F;

/**
 * Module 6.4 test data, written straight to the tables as the tills and the nightly report rebuild leave them:
 * supplier links, stock lines, daily product sales, deliveries (for lead times), open orders, transfers, batches
 * and seasonal events.
 */
final class ReorderFixtures
{
    public static function link(Company $company, string $product, int $caseQty, string $caseCost, string $supplier = F::SUPPLIER, bool $preferred = true): void
    {
        DB::table('product_suppliers')->insert(['id' => Ulid::new(), 'company_id' => $company->id, 'product_id' => $product, 'supplier_id' => $supplier,
            'case_qty' => $caseQty, 'case_cost' => $caseCost, 'is_preferred' => $preferred, 'row_version' => 1]);
    }

    public static function stock(Company $company, Branch $shop, string $product, string $onHand, ?string $reorderPoint = null, ?string $max = null): void
    {
        F::row('branch_products', $company, $shop, ['product_id' => $product, 'qty_on_hand' => $onHand, 'reorder_point' => $reorderPoint,
            'max_qty' => $max, 'is_active' => true, 'is_stock_tracked' => true]);
    }

    /**
     * Units sold each day of the `$weeks` weeks before `$today`: `$perDay(CarbonImmutable $day)`.
     */
    public static function sales(Company $company, Branch $shop, string $product, string $today, callable $perDay, int $weeks = 8): void
    {
        $rows = [];
        $day = CarbonImmutable::parse($today);

        for ($i = 1; $i <= 7 * $weeks; $i++) {
            $date = $day->subDays($i);
            $units = (string) $perDay($date);

            if ($units !== '0') {
                $rows[] = ['company_id' => $company->id, 'branch_id' => $shop->id, 'trading_day' => $date->toDateString(), 'register_id' => '',
                    'product_id' => $product, 'qty' => $units, 'refund_qty' => 0, 'last_name' => 'x', 'rebuilt_at' => '2026-09-23 00:00:00'];
            }
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('rpt_product_daily')->insert($chunk);
        }
    }

    /** A received order sent on `$sent` (UTC) and booked in on `$received`. */
    public static function delivery(Company $company, Branch $shop, string $sent, string $received, string $supplier = F::SUPPLIER): void
    {
        $order = F::row('purchase_orders', $company, $shop, ['supplier_id' => $supplier, 'status' => 'received', 'sent_at' => $sent, 'number' => random_int(1000, 99999)]);
        F::row('goods_receipts', $company, $shop, ['supplier_id' => $supplier, 'purchase_order_id' => $order, 'received_date' => $received, 'status' => 'posted']);
    }

    public static function openOrder(Company $company, Branch $shop, string $product, string $units, string $received = '0', string $status = 'sent'): string
    {
        $order = F::row('purchase_orders', $company, $shop, ['supplier_id' => F::SUPPLIER, 'status' => $status, 'number' => random_int(1000, 99999)]);
        F::row('purchase_order_lines', $company, $shop, ['purchase_order_id' => $order, 'product_id' => $product, 'ordered_units' => $units, 'received_qty' => $received]);

        return $order;
    }

    public static function transfer(Company $company, Branch $from, Branch $to, string $product, string $qty, string $status = 'dispatched'): void
    {
        $transfer = F::row('stock_transfers', $company, $from, ['from_branch_id' => $from->id, 'to_branch_id' => $to->id, 'status' => $status, 'reference' => 'TR-1']);
        F::row('stock_transfer_lines', $company, $from, ['transfer_id' => $transfer, 'product_id' => $product, 'qty_dispatched' => $qty, 'qty_requested' => $qty]);
    }

    public static function batch(Company $company, Branch $shop, string $product, string $receivedAt, string $expiry): void
    {
        F::row('stock_layers', $company, $shop, ['product_id' => $product, 'qty_remaining' => 0, 'unit_cost' => '0.50', 'received_at' => $receivedAt, 'expiry_date' => $expiry]);
    }

    public static function event(Company $company, Branch $shop, string $name, string $starts, string $ends): string
    {
        return F::row('seasonal_events', $company, $shop, ['name' => $name, 'kind' => 'local', 'starts_on' => $starts, 'ends_on' => $ends, 'is_active' => true]);
    }

    /**
     * The standard scenario ("today" Wed 23 Sept 2026). Cola: case 24 at £12 (50p each), product min 6 / reorder qty 24.
     * Leeds: 10 on hand, minimum 12, sells 6 a day, 24 on a sent order, two deliveries took 3 days each → 2 cases.
     * Bradford: none on hand, sells 2 a day, lead time 3 from Leeds's deliveries → 2 cases. Water: case 12 at £6;
     * Leeds has 100 and sells 1 a day → nothing (overstocked).
     */
    public static function scenario(Company $company, Branch $leeds, Branch $bradford): void
    {
        self::link($company, F::COLA, 24, '12.0000');
        self::link($company, F::WATER, 12, '6.0000');
        self::stock($company, $leeds, F::COLA, '10', reorderPoint: '12');
        self::stock($company, $leeds, F::WATER, '100');
        self::stock($company, $bradford, F::COLA, '0');
        DB::table('rpt_product_daily')->where('company_id', $company->id)->delete();
        self::sales($company, $leeds, F::COLA, '2026-09-23', fn () => 6);
        self::sales($company, $leeds, F::WATER, '2026-09-23', fn () => 1);
        self::sales($company, $bradford, F::COLA, '2026-09-23', fn () => 2);
        self::delivery($company, $leeds, '2026-09-01 08:00:00', '2026-09-04');
        self::delivery($company, $leeds, '2026-09-08 08:00:00', '2026-09-11');
        self::openOrder($company, $leeds, F::COLA, '24');
    }
}
