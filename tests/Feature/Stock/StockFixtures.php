<?php

namespace Tests\Feature\Stock;

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Till rows for the stock screens' tests (module 5.1), written straight to the tables as a push would store them.
 */
final class StockFixtures
{
    /** A 26-character id: the prefix padded with zeros, then the number. */
    public static function id(string $prefix, int $n): string
    {
        return strtoupper(str_pad($prefix, 22, '0')).sprintf('%04d', $n);
    }

    public static function member(Company $company, CompanyRole $role, ?string $branchId = null): User
    {
        $user = User::factory()->create();
        $company->users()->attach($user->id, ['role' => $role->value, 'is_active' => true, 'branch_id' => $branchId]);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    public static function product(string $companyId, string $id, string $name, string $cost = '0', array $overrides = []): void
    {
        DB::table('products')->insert([
            'id' => $id, 'company_id' => $companyId, 'name' => $name, 'sku' => 'SKU-'.substr($id, -4), 'cost_price' => $cost,
            'track_stock' => true, 'is_active' => true, 'row_version' => 1, ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public static function line(string $companyId, string $branchId, string $productId, string $qty, array $overrides = []): void
    {
        DB::table('branch_products')->insert([
            'id' => 'L'.substr($branchId, -4).substr($productId, -21), 'company_id' => $companyId, 'branch_id' => $branchId,
            'product_id' => $productId, 'is_active' => true, 'qty_on_hand' => $qty, 'qty_reserved' => '0', ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public static function movement(string $companyId, string $branchId, string $id, string $productId, string $type, string $qty, string $at, array $overrides = []): void
    {
        DB::table('stock_movements')->insert([
            'id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId, 'product_id' => $productId, 'type' => $type,
            'qty_delta' => $qty, 'qty_before' => '0', 'qty_after' => $qty, 'unit_cost' => '0.5000', 'at' => $at, 'note' => '',
            'ref_type' => '', 'ref_id' => '', 'ref_line_id' => '', 'user_id' => '', ...$overrides,
        ]);
    }

    public static function fifo(string $companyId, string $branchId, string $id, string $productId, string $qty, string $cost, string $receivedAt): void
    {
        DB::table('fifo_stock_layers')->insert([
            'id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId, 'product_id' => $productId,
            'qty_remaining' => $qty, 'unit_cost' => $cost, 'received_at' => $receivedAt, 'ref_type' => 'goodsReceipt', 'ref_id' => '',
        ]);
    }

    public static function batch(string $companyId, string $branchId, string $id, string $productId, string $qty, string $cost, ?string $expiry, string $batchNo = 'B1'): void
    {
        DB::table('stock_layers')->insert([
            'id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId, 'product_id' => $productId, 'grn_line_id' => '',
            'qty_remaining' => $qty, 'unit_cost' => $cost, 'received_at' => '2026-09-01 08:00:00', 'expiry_date' => $expiry, 'batch_no' => $batchNo,
        ]);
    }

    /**
     * A stock take and its lines: [product id, name, expected, counted|null, unit cost, variance qty, variance cost].
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string|null, 4: string, 5: string, 6: string}>  $lines
     */
    public static function take(string $companyId, string $branchId, string $id, string $status, string $startedAt, array $lines): void
    {
        DB::table('stock_takes')->insert([
            'id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId, 'reference' => 'ST-'.substr($id, -4), 'name' => 'Count '.substr($id, -4),
            'scope' => 'branch', 'scope_id' => '', 'status' => $status, 'is_blind' => false, 'count_uncounted_as_zero' => false,
            'started_by_user_id' => '', 'started_at' => $startedAt, 'approved_by_user_id' => '', 'note' => '', 'is_high_value_count' => false,
        ]);

        foreach ($lines as $i => [$productId, $name, $expected, $counted, $cost, $varQty, $varCost]) {
            DB::table('stock_take_lines')->insert([
                'id' => substr($id, 0, 22).sprintf('%04d', $i + 1), 'company_id' => $companyId, 'branch_id' => $branchId, 'stock_take_id' => $id,
                'product_id' => $productId, 'product_name' => $name, 'section_id' => '', 'snapshot_qty' => $expected, 'counted_qty' => $counted,
                'unit_cost' => $cost, 'count_count' => $counted === null ? 0 : 1, 'counted_by_user_id' => '', 'note' => '',
                'recount_required' => false, 'variance_qty' => $varQty, 'variance_cost' => $varCost,
            ]);
        }
    }
}
