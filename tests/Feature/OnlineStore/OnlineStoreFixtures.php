<?php

namespace Tests\Feature\OnlineStore;

use App\Domain\OnlineStore\Actions\PlaceCollectOrder;
use App\Domain\OnlineStore\Actions\SaveOnlineStore;
use App\Domain\OnlineStore\Actions\SaveStoreShop;
use App\Domain\OnlineStore\Actions\SetStoreLive;
use App\Domain\OnlineStore\Actions\SetTakingOrders;
use App\Domain\OnlineStore\Data\CollectOrderInput;
use App\Domain\OnlineStore\Data\PlacedOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Online store test data: a business with two shops (Leeds, Bradford), a department and category, a live store
 * taking orders with Leeds as its collection shop (every day 08:00–20:00, 30-minute slots, 2 orders each, 30
 * minutes to get ready). Till rows are written straight to the tables, as a till push would leave them.
 */
final class OnlineStoreFixtures
{
    public Company $company;

    public Branch $leeds;

    public Branch $bradford;

    public OnlineStore $store;

    public OnlineStoreShop $shop;

    public string $department;

    public string $category;

    public static function make(string $name = 'Kirkgate Convenience', string $slug = 'kirkgate'): self
    {
        config(['online_store.enabled' => true]);

        $f = new self;
        $f->company = Company::factory()->create(['name' => $name]);
        $f->leeds = Branch::factory()->forCompany($f->company)->create(['code' => 'LDS', 'name' => 'Leeds']);
        $f->bradford = Branch::factory()->forCompany($f->company)->create(['code' => 'BRD', 'name' => 'Bradford']);
        $f->department = self::department($f->company, 'Grocery', 1);
        $f->category = self::category($f->company, $f->department, 'Cupboard');
        $f->store = app(SaveOnlineStore::class)->handle($f->company, ['slug' => $slug, 'template' => 'freshMarket', 'order_email' => 'orders@example.test']);
        $f->shop = app(SaveStoreShop::class)->handle($f->company, $f->leeds->id, self::shopInput());
        app(SetStoreLive::class)->handle($f->company, true);
        $f->store = app(SetTakingOrders::class)->handle($f->company, true)->refresh();

        return $f;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function shopInput(array $overrides = []): array
    {
        $day = ['open' => '08:00', 'close' => '20:00'];

        return [
            'opening_hours' => array_fill_keys(OnlineStoreShop::WEEKDAYS, $day),
            'prep_minutes' => 30,
            'slot_minutes' => 30,
            'orders_per_slot' => 2,
            'enabled' => true,
            ...$overrides,
        ];
    }

    public static function department(Company $company, string $name, int $position): string
    {
        $id = Ulid::new();
        DB::table('departments')->insert(['id' => $id, 'company_id' => $company->id, 'name' => $name, 'position' => $position, 'is_active' => true, 'row_version' => 1]);

        return $id;
    }

    /** @param array<string, mixed> $overrides */
    public static function category(Company $company, string $departmentId, string $name, array $overrides = []): string
    {
        $id = Ulid::new();
        DB::table('categories')->insert(['id' => $id, 'company_id' => $company->id, 'department_id' => $departmentId, 'name' => $name,
            'position' => 1, 'is_active' => true, 'row_version' => 1, ...$overrides]);

        return $id;
    }

    /**
     * A product sold at £1.00, not stock-tracked, in the fixture's department.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function product(string $name, array $overrides = []): string
    {
        $id = (string) ($overrides['id'] ?? Ulid::new());
        DB::table('products')->insert([
            'id' => $id, 'company_id' => $this->company->id, 'name' => $name, 'short_name' => $name, 'sku' => 'SKU-'.substr($id, -6),
            'department_id' => $this->department, 'category_id' => $this->category, 'unit_code' => 'each', 'sell_price' => '1.00',
            'cost_price' => '0.5000', 'track_stock' => false, 'is_active' => true, 'is_weighed' => false, 'row_version' => 1, ...$overrides,
        ]);

        return $id;
    }

    /** @param array<string, mixed> $overrides */
    public function stock(Branch $shop, string $productId, string $qty, array $overrides = []): void
    {
        DB::table('branch_products')->insert([
            'id' => Ulid::new(), 'company_id' => $this->company->id, 'branch_id' => $shop->id, 'product_id' => $productId, 'is_active' => true,
            'qty_on_hand' => $qty, 'qty_reserved' => '0', 'row_version' => 1, ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    public function shopPrice(Branch $shop, string $productId, string $price, string $from = '2026-01-01 00:00:00', ?string $to = null, array $overrides = []): void
    {
        DB::table('branch_prices')->insert([
            'id' => Ulid::new(), 'company_id' => $this->company->id, 'branch_id' => $shop->id, 'product_id' => $productId, 'price' => $price,
            'valid_from_utc' => $from, 'valid_to_utc' => $to, 'row_version' => 1, ...$overrides,
        ]);
    }

    public function medicineClass(string $productId, string $class): void
    {
        DB::table('medicine_classifications')->insert(['id' => Ulid::new(), 'company_id' => $this->company->id, 'product_id' => $productId, 'class' => $class, 'note' => '', 'row_version' => 1]);
    }

    /**
     * @param  list<array{productId: string, quantity: string}>  $lines
     * @param  array<string, mixed>  $overrides
     */
    public function input(array $lines, string $collectFrom, array $overrides = []): CollectOrderInput
    {
        return CollectOrderInput::fromArray([
            'shopId' => $this->shop->id, 'collectFrom' => $collectFrom, 'customerName' => 'Asha Patel', 'customerMobile' => '07700 900123',
            'customerEmail' => 'asha@example.test', 'lines' => $lines, ...$overrides,
        ]);
    }

    /**
     * @param  list<array{productId: string, quantity: string}>  $lines
     */
    public function order(array $lines, string $collectFrom): PlacedOrder
    {
        return app(PlaceCollectOrder::class)->handle($this->store, $this->input($lines, $collectFrom));
    }

    public static function asPakistan(): void
    {
        config(['country.code' => 'PK']);
        app()->forgetInstance(Country::class);
    }
}
