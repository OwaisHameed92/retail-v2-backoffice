<?php

namespace Tests\Feature\Purchasing;

use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\TillData\TillFixtures;

/**
 * Module 5.2 test data: the head-office sample's supplier, VAT rates and products, and the shops' own purchasing rows
 * written straight to the tables (as a till push would leave them).
 */
final class PurchasingFixtures
{
    public const SUPPLIER = '01K5T0Q8C4000000000000S001';

    public const WATER = '01K5T0Q8C4000000000000P001';

    public const COLA = '01K5T0Q8C4000000000000P002';

    public const VAT_STD = '01K5T0Q8C4000000000000V001';

    public const VAT_ZERO = '01K5T0Q8C4000000000000V002';

    /** Supplier, a 20% and a 0% VAT rate, water (0%) and cola (20%). */
    public static function catalogue(Company $company): void
    {
        $sample = TillFixtures::sample('pull-reply.head-office.json')['changes'];
        $vat = TillFixtures::sample('entities/VatRate.json');
        $product = TillFixtures::sample('entities/Product.json');

        Pull::portalCreate($company, 'Supplier', $sample[0]['payload']);
        Pull::portalCreate($company, 'VatRate', $vat);
        Pull::portalCreate($company, 'VatRate', [...$vat, 'id' => self::VAT_ZERO, 'name' => 'Zero', 'percentage' => 0]);
        Pull::portalCreate($company, 'Product', $product);
        Pull::portalCreate($company, 'Product', [...$product, 'id' => self::COLA, 'name' => 'Coca-Cola 500ml', 'sku' => 'COLA-500', 'vatRateId' => self::VAT_STD]);
    }

    /**
     * A shop's own row in a till-owned table.
     *
     * @param  array<string, mixed>  $columns
     */
    public static function row(string $table, Company $company, ?Branch $shop, array $columns): string
    {
        $id = $columns['id'] ?? Ulid::new();
        DB::table($table)->insert([
            'id' => $id, 'company_id' => $company->id, 'branch_id' => $shop?->id, 'row_version' => 1,
            'created_at' => '2026-09-20 09:00:00', 'updated_at' => '2026-09-20 09:00:00', ...$columns,
        ]);

        return $id;
    }

    /** @param array<string, mixed> $columns */
    public static function invoice(Company $company, Branch $shop, string $date, string $gross, array $columns = []): string
    {
        return self::row('supplier_invoices', $company, $shop, [
            'supplier_id' => self::SUPPLIER, 'supplier_name' => 'Booker', 'invoice_number' => 'INV-'.$date, 'invoice_date' => $date,
            'due_date' => $date, 'status' => 'approved', 'net_amount' => $gross, 'vat_amount' => '0.00', 'gross_amount' => $gross,
            'amount_paid' => '0.00', 'balance' => $gross, ...$columns,
        ]);
    }

    /** @param array<string, mixed> $columns */
    public static function credit(Company $company, Branch $shop, string $date, string $gross, array $columns = []): string
    {
        return self::row('supplier_credit_notes', $company, $shop, [
            'supplier_id' => self::SUPPLIER, 'supplier_name' => 'Booker', 'credit_note_number' => 'CN-'.$date, 'credit_date' => $date,
            'reason' => 'Damaged', 'net_amount' => $gross, 'vat_amount' => '0.00', 'gross_amount' => $gross, 'applied_amount' => '0.00', 'balance' => $gross, ...$columns,
        ]);
    }

    /** @param array<string, mixed> $columns */
    public static function payment(Company $company, Branch $shop, string $date, string $amount, array $columns = []): string
    {
        return self::row('supplier_payments', $company, $shop, [
            'supplier_id' => self::SUPPLIER, 'supplier_name' => 'Booker', 'payment_date' => $date, 'method' => 'bankTransfer', 'reference' => 'BACS-'.$date,
            'amount' => $amount, 'allocated_amount' => $amount, 'unallocated' => '0.00', 'is_reversed' => false, ...$columns,
        ]);
    }

    public static function member(Company $company, CompanyRole $role, ?Branch $shop = null): User
    {
        $user = User::factory()->create();
        $company->users()->attach($user->id, ['role' => $role->value, 'is_active' => true, 'branch_id' => $shop?->id]);

        return $user;
    }

    /**
     * The order form's input: 2 cases of 12 water at £0.98 (0%) and 2 cases of 24 cola at £0.78 (20%).
     *
     * @return array<string, mixed>
     */
    public static function orderInput(?Branch $shop, string $status = 'draft', array $overrides = []): array
    {
        return [
            'shopId' => $shop?->id, 'supplierId' => self::SUPPLIER, 'status' => $status, 'expectedDate' => '2026-09-25', 'notes' => 'Weekly top-up',
            'lines' => [
                ['productId' => self::WATER, 'orderedCases' => 2, 'caseQty' => 12, 'looseUnits' => 0, 'unitCost' => '0.98', 'vatRateId' => self::VAT_ZERO, 'vatPercentage' => '99'],
                ['productId' => self::COLA, 'orderedCases' => 2, 'caseQty' => 24, 'looseUnits' => 0, 'unitCost' => '0.78', 'vatRateId' => self::VAT_STD],
            ],
            ...$overrides,
        ];
    }
}
