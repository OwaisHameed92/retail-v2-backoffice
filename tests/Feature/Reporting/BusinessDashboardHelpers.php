<?php

namespace Tests\Feature\Reporting;

use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Tests\Feature\TillData\TillFixtures;

/**
 * Module 3.3 test helpers: the sample basket (net £4.53, gross £5.15, VAT £0.62, card £5.15) pushed for any business,
 * and the raw till rows the non-sales tiles read.
 */
final class BusinessDashboardHelpers
{
    public static function basket(Company $company, Branch $branch, string $till, int $n, string $at, array $o = []): void
    {
        $rows = ReportFixtures::basket((string) $n, $at, $n * 10, ['register' => $till, 'branch' => $branch->id] + $o);
        $rows = json_decode(str_replace(TillFixtures::COMPANY, $company->id, (string) json_encode($rows)), true);
        ReportFixtures::push($company, $branch, $rows);
    }

    /** A cash shift closed at `$closedAt` (UTC) with a cash line of `$variance` and a card line of +£1 (never counted). */
    public static function shift(string $companyId, string $branchId, string $id, string $closedAt, string $variance): void
    {
        foreach (['CASH' => [true, $variance], 'CARD' => [false, '1.00']] as $type => [$cash, $amount]) {
            $typeId = substr($companyId, 0, 22).$type;
            DB::table('payment_types')->insertOrIgnore(['id' => $typeId, 'company_id' => $companyId, 'name' => $type, 'is_cash' => $cash]);
            DB::table('shift_tenders')->insert(['id' => "{$id}{$type}", 'company_id' => $companyId, 'branch_id' => $branchId, 'shift_id' => $id, 'payment_type_id' => $typeId, 'variance' => $amount]);
        }

        DB::table('shifts')->insert(['id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId, 'status' => 'closed', 'closed_at' => $closedAt]);
    }

    /** A product with stock `$qty` in a shop; `$min` = Product.minStockQty (null: fall back to the threshold). */
    public static function stock(string $companyId, string $branchId, string $id, string $qty, ?string $min = null, bool $tracked = true): void
    {
        DB::table('products')->insertOrIgnore(['id' => $id, 'company_id' => $companyId, 'name' => "Product {$id}", 'track_stock' => $tracked, 'min_stock_qty' => $min]);
        DB::table('branch_products')->insert(['id' => $id.substr($branchId, -4), 'company_id' => $companyId, 'branch_id' => $branchId, 'product_id' => $id, 'is_active' => true, 'qty_on_hand' => $qty]);
    }
}
