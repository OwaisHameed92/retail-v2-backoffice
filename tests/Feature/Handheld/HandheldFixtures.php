<?php

namespace Tests\Feature\Handheld;

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\TillData\TillFixtures;
use Tests\TestCase;

/**
 * Handheld (PDA) API test data: handheld users, a signed-in token, and a second business with a product, a supplier
 * and an order of its own (isolation). The first business is SyncApiFixtures' (Leeds + Bradford, sync keys) with
 * PurchasingFixtures' catalogue (supplier, water, cola).
 */
final class HandheldFixtures
{
    public const PASSWORD = 'correct-horse-battery';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function user(Company $company, Branch $shop, array $attributes = []): HandheldUser
    {
        return HandheldUser::withoutCompanyScope()->create([
            'company_id' => $company->id,
            'branch_id' => $shop->id,
            'name' => 'Stock room 1',
            'email' => 'stock1@kirkgate.test',
            'password' => self::PASSWORD,
            'is_active' => true,
            'can_change_prices' => false,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function login(TestCase $test, array $body): TestResponse
    {
        return $test->postJson('/api/pda/login', $body);
    }

    /** Signs the handheld user in and returns the plain token. */
    public static function token(TestCase $test, HandheldUser $user, string $password = self::PASSWORD): string
    {
        return (string) self::login($test, ['email' => $user->email, 'password' => $password, 'device_name' => 'Zebra TC21'])->assertOk()->json('token');
    }

    /** @return array<string, string> */
    public static function headers(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    /**
     * Another business with one shop, product, supplier, barcode and order (ids returned).
     *
     * @return array{company: Company, shop: Branch, product: string, supplier: string, order: string}
     */
    public static function otherBusiness(): array
    {
        $company = Company::factory()->create(['name' => 'Other Stores']);
        $shop = Branch::factory()->forCompany($company)->create(['code' => 'OTH', 'name' => 'Other shop']);
        $product = Ulid::new();
        $supplier = Ulid::new();
        $sample = TillFixtures::sample('entities/Product.json');

        Pull::portalCreate($company, 'Product', [...$sample, 'id' => $product, 'name' => 'Secret cola', 'sku' => 'SECRET-1', 'companyId' => $company->id]);
        Pull::portalCreate($company, 'Supplier', Pull::payload('Supplier', $supplier, ['name' => 'Secret supplier', 'code' => 'SEC', 'isActive' => true]));
        Pull::portalCreate($company, 'ProductBarcode', Pull::payload('ProductBarcode', Ulid::new(), ['productId' => $product, 'barcode' => '5000000000999', 'packQty' => 1, 'isPrimary' => true]));
        $order = F::row('purchase_orders', $company, $shop, ['supplier_id' => $supplier, 'number' => 1, 'status' => 'draft', 'origin' => 'headOffice',
            'order_no' => 'HO-000001', 'branch_code' => 'OTH', 'reference' => 'HO-OTH-000001', 'net_total' => '1.00', 'vat_total' => '0.00', 'gross_total' => '1.00',
            'hub_drafted_at' => '2026-09-20 09:00:00', 'row_version' => 0]);

        return ['company' => $company, 'shop' => $shop, 'product' => $product, 'supplier' => $supplier, 'order' => $order];
    }

    /** The department and category the contract's Product sample names (SaveProduct checks them). */
    public static function catalogueGroups(Company $company): void
    {
        $product = TillFixtures::sample('entities/Product.json');

        Pull::portalCreate($company, 'Department', Pull::payload('Department', $product['departmentId'], ['name' => 'Bakery', 'isActive' => true, 'colourHex' => '#C98A2B']));
        Pull::portalCreate($company, 'Category', Pull::payload('Category', $product['categoryId'], [
            'name' => 'Bread', 'departmentId' => $product['departmentId'], 'parentCategoryId' => null, 'isActive' => true, 'ageRuleDefault' => 'none', 'colourHex' => '#C98A2B',
        ]));
    }

    /** A product barcode of the first business. */
    public static function barcode(Company $company, string $productId, string $barcode, int $packQty = 1, ?string $productUnitId = null, bool $primary = true, ?string $unitCode = null): void
    {
        Pull::portalCreate($company, 'ProductBarcode', Pull::payload('ProductBarcode', Ulid::new(), [
            'productId' => $productId, 'barcode' => $barcode, 'packQty' => $packQty, 'isPrimary' => $primary, 'productUnitId' => $productUnitId, 'unitCode' => $unitCode,
        ]));
    }

    /** Stock on hand as the shop's till reported it. */
    public static function stock(Company $company, Branch $shop, string $productId, string $qty): void
    {
        DB::table('branch_products')->insert(['id' => Ulid::new(), 'company_id' => $company->id, 'branch_id' => $shop->id, 'product_id' => $productId,
            'is_active' => true, 'qty_on_hand' => $qty, 'qty_reserved' => '0', 'qty_available' => $qty, 'row_version' => 1,
            'created_at' => '2026-09-20 09:00:00', 'updated_at' => '2026-09-20 09:00:00']);
    }

    /** A live shop price (base unit, or a unit) set on the portal. */
    public static function shopPrice(Company $company, Branch $shop, string $productId, string $price, ?string $productUnitId = null): void
    {
        Pull::portalCreate($company, 'BranchPrice', Pull::payload('BranchPrice', Ulid::new(), [
            'branchId' => $shop->id, 'productId' => $productId, 'productUnitId' => $productUnitId, 'price' => (float) $price,
            'validFromUtc' => '2026-09-01T00:00:00Z', 'validToUtc' => null,
        ]));
    }
}
