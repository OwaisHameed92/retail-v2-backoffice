<?php

use App\Domain\OnlineStore\Actions\SaveOnlineStore;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreProduct;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

/*
| OS-11: what the home page's rows may show. Audit OSA-31: no HFSS product in a home row (still in search), deposit
| return items show their deposit, medicine pack limits come from `max_qty_per_sale`. Best sellers leave out tiny
| service items (under £0.50, carrier bags, bag charges) and products the shop took out of "featured". Junk till names
| ("," or "-") are never shown; names in capitals are shown in Title Case.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    $this->url = fn (string $name, array $params = []) => route($name, ['slug' => 'kirkgate', ...$params], false);
    $this->sold = fn (string $productId, string $qty) => DB::table('rpt_product_daily')->insert(['company_id' => $this->f->company->id,
        'branch_id' => $this->f->leeds->id, 'trading_day' => '2026-10-30', 'register_id' => Ulid::new(), 'product_id' => $productId, 'qty' => $qty,
        'refund_qty' => '0', 'net' => '1.00', 'rebuilt_at' => now()]);
    $this->sections = fn (array $keys) => OnlineStore::withoutCompanyScope()->whereKey($this->f->store->id)
        ->update(['sections' => json_encode(array_map(fn ($k) => ['key' => $k, 'enabled' => true], $keys))]);
});

test('best sellers leave out HFSS, tiny service items, bag charges and products the shop took out of featured', function () {
    $milk = $this->f->product('Milk');
    $choc = $this->f->product('Chocolate bar', ['is_hfss' => true]);
    $bag = $this->f->product('Carrier Bag', ['sell_price' => '0.10']);
    $levy = $this->f->product('Bag charge', ['sell_price' => '0.70']);
    $cheap = $this->f->product('Penny sweet', ['sell_price' => '0.05']);
    $service = OnlineStoreFixtures::department($this->f->company, 'CARRIER BAGS', 9);
    $big = $this->f->product('Bag for life', ['sell_price' => '0.70', 'department_id' => $service]);
    $mine = $this->f->product('Gift wrap', ['sell_price' => '2.00']);
    OnlineStoreProduct::withoutCompanyScope()->create(['company_id' => $this->f->company->id, 'product_id' => $mine, 'not_featured' => true]);

    foreach ([$milk, $choc, $bag, $levy, $cheap, $big, $mine] as $i => $id) {
        ($this->sold)($id, (string) (100 - $i));
    }

    $this->get(($this->url)('store.home'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('featuredMode', 'bestSellers')
        ->where('featured', fn ($items) => collect($items)->pluck('name')->all() === ['Milk']));

    // Still sold and found by search.
    $this->get(($this->url)('store.search', ['q' => 'chocolate']))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('products.0.name', 'Chocolate bar'));
});

test('hand-picked featured, on offer and department rows never show an HFSS product', function () {
    $milk = $this->f->product('Milk');
    $choc = $this->f->product('Chocolate bar', ['is_hfss' => true]);
    app(SaveOnlineStore::class)->handle($this->f->company, ['slug' => 'kirkgate', 'featured_mode' => 'handPicked', 'featured_product_ids' => [$choc, $milk]]);
    DB::table('promotion_rules')->insert(['id' => Ulid::new(), 'company_id' => $this->f->company->id, 'name' => 'Offer', 'type' => 'multiBuy',
        'scope' => 'department', 'target_id' => $this->f->department, 'buy_quantity' => 2, 'deal_price' => '1.50', 'effective_from' => '2026-10-01',
        'is_active' => true, 'row_version' => 1]);
    ($this->sections)(['featured', 'offers', 'departmentRows']);

    $this->get(($this->url)('store.home'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('featured', fn ($items) => collect($items)->pluck('name')->all() === ['Milk'])
        ->where('offers', fn ($items) => collect($items)->pluck('name')->all() === ['Milk'])
        ->where('departmentRows.0.products', fn ($items) => collect($items)->pluck('name')->all() === ['Milk']));
});

test('the shop takes a product out of featured on the Products tab', function () {
    $milk = $this->f->product('Milk');
    $owner = C::member($this->f->company, CompanyRole::Owner);

    $this->actingAs($owner)->post("/app/online-store/products/{$milk}", ['not_featured' => '1'])->assertSessionHasNoErrors();
    expect(OnlineStoreProduct::withoutCompanyScope()->where('product_id', $milk)->value('not_featured'))->toBeTrue();
    $this->actingAs($owner)->get('/app/online-store/products')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('products.data.0.notFeatured', true));
});

test('deposit return items show their deposit; pack limits come from max per sale', function () {
    $water = $this->f->product('Still water 500ml', ['is_deposit_item' => true, 'deposit_amount' => '0.10']);
    $pills = $this->f->product('Paracetamol 16', ['max_qty_per_sale' => 2]);

    $this->get(($this->url)('store.product', ['product' => $water]))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('product.deposit', '0.10'));
    $this->get(($this->url)('store.product', ['product' => $pills]))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('product.deposit', null)
        ->where('product.max', '2'));
});

test('junk till names are never shown; capitals are shown in Title Case', function () {
    $junk = $this->f->product(',');
    $this->f->product('-');
    $this->f->product('WALKERS CRISPS 6PK');
    $this->f->product('Oat milk');
    ($this->sold)($junk, '500');

    $this->get(($this->url)('store.search'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('products', fn ($items) => collect($items)->pluck('name')->all() === ['Oat milk', 'Walkers Crisps 6pk']));
    $this->get(($this->url)('store.home'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('featured', fn ($items) => ! collect($items)->pluck('name')->contains(',')));
    $this->get(($this->url)('store.product', ['product' => $junk]))->assertNotFound();
});
