<?php

use App\Domain\OnlineStore\Actions\SaveOnlineStore;
use App\Domain\OnlineStore\Actions\SaveStoreBanner;
use App\Domain\OnlineStore\Actions\SetOnlineProduct;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\BrandPalette;
use App\Domain\Shared\Support\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

/*
 * OS-8: the public store's look and data: banners (slides), featured products (best sellers, hand-picked, newest),
 * the "On offer" and department rows, photos (online, else the master catalogue by barcode), allergens, the
 * server-side head (meta, robots, JSON-LD), sitemap and robots.txt, and business isolation of all of it.
 */

beforeEach(function () {
    Storage::fake('public');
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    $this->url = fn (string $name, array $params = []) => route($name, ['slug' => 'kirkgate', ...$params], false);
    $this->sold = function (string $productId, string $qty, ?string $branchId = null, string $day = '2026-10-30', ?OnlineStoreFixtures $f = null) {
        $f ??= $this->f;
        DB::table('rpt_product_daily')->insert(['company_id' => $f->company->id, 'branch_id' => $branchId ?? $f->leeds->id, 'trading_day' => $day,
            'register_id' => Ulid::new(), 'product_id' => $productId, 'qty' => $qty, 'refund_qty' => '0', 'net' => '1.00', 'rebuilt_at' => now()]);
    };
    $this->sections = fn (array $keys) => OnlineStore::withoutCompanyScope()->whereKey($this->f->store->id)
        ->update(['sections' => json_encode(array_map(fn ($k) => ['key' => $k, 'enabled' => true], $keys))]);
});

test('banners: the ones that are on, in order, with their links; an external link is https only', function () {
    $this->f->product('Bread');
    $save = app(SaveStoreBanner::class);
    $save->handle($this->f->company, null, ['headline' => 'Bread', 'link_type' => 'department', 'link_target' => $this->f->department],
        UploadedFile::fake()->image('b.jpg', 800, 400));
    $save->handle($this->f->company, null, ['headline' => 'Hidden', 'is_on' => false]);
    $save->handle($this->f->company, null, ['headline' => 'Offers', 'button_label' => 'See offers', 'link_type' => 'url', 'link_target' => 'https://example.test/x']);

    $this->get(($this->url)('store.home'))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('banners', 2)
        ->where('banners.0.headline', 'Bread')
        ->where('banners.0.href', route('store.department', ['slug' => 'kirkgate', 'department' => $this->f->department], false))
        ->where('banners.0.photoUrl', fn ($url) => str_contains($url, "online-store/{$this->f->company->id}/banner/"))
        ->where('banners.1.headline', 'Offers')
        ->where('banners.1.buttonLabel', 'See offers')
        ->where('banners.1.external', true)
        ->where('banners.1.photoUrl', asset('images/online-store/hero-grocery.jpg'))
        ->where('headline', 'Bread'));
});

test('featured: best sellers at the collection shops over the last 30 days, shown and orderable, filled up with the newest', function () {
    $beans = $this->f->product('Baked beans', ['created_at' => '2026-01-01 00:00:00']);
    $milk = $this->f->product('Milk', ['created_at' => '2026-01-02 00:00:00']);
    $whisky = $this->f->product('Whisky', ['is_alcohol' => true]);
    $gone = $this->f->product('Out of stock', ['track_stock' => true]);
    $newest = $this->f->product('Zesty new crisps', ['created_at' => '2026-10-30 00:00:00']);
    ($this->sold)($milk, '50');
    ($this->sold)($beans, '20');
    ($this->sold)($whisky, '99');
    ($this->sold)($gone, '80');
    ($this->sold)($beans, '500', day: '2026-09-01');           // too long ago
    ($this->sold)($newest, '5', $this->f->bradford->id);         // not a collection shop

    $this->get(($this->url)('store.home'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('featuredMode', 'bestSellers')
        ->where('featured', fn ($items) => collect($items)->pluck('name')->take(3)->all() === ['Milk', 'Baked beans', 'Zesty new crisps']
            && ! collect($items)->pluck('name')->contains('Whisky') && ! collect($items)->pluck('name')->contains('Out of stock')));
});

test('featured: no sales yet reads as newest; hand-picked keeps the chosen order and leaves out what is not shown', function () {
    $old = $this->f->product('Old faithful', ['created_at' => '2025-01-01 00:00:00']);
    $new = $this->f->product('Brand new', ['created_at' => '2026-10-31 00:00:00']);
    $hidden = $this->f->product('Hidden one');
    app(SetOnlineProduct::class)->handle($this->f->company, $hidden, ['hidden' => true]);

    $this->get(($this->url)('store.home'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('featuredMode', 'newest')->where('featured.0.name', 'Brand new'));

    app(SaveOnlineStore::class)->handle($this->f->company, ['slug' => 'kirkgate', 'featured_mode' => 'handPicked', 'featured_product_ids' => [$old, $hidden, $new]]);

    $this->get(($this->url)('store.home'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('featuredMode', 'handPicked')
        ->where('featured', fn ($items) => collect($items)->pluck('name')->all() === ['Old faithful', 'Brand new']));
});

test('featured settings only take the business\'s own products and departments, at most 24', function () {
    $other = OnlineStoreFixtures::make('Other Shop', 'other-shop');
    $theirs = $other->product('Theirs');
    $save = fn (array $input) => app(SaveOnlineStore::class)->handle($this->f->company, ['slug' => 'kirkgate', ...$input]);

    expect(fn () => $save(['featured_product_ids' => [$theirs]]))->toThrow(ValidationException::class)
        ->and(fn () => $save(['home_departments' => [$other->department]]))->toThrow(ValidationException::class)
        ->and(fn () => $save(['featured_mode' => 'random']))->toThrow(ValidationException::class)
        ->and(fn () => $save(['featured_product_ids' => array_fill(0, 25, $theirs)]))->toThrow(ValidationException::class);

    $store = $save(['featured_mode' => 'newest', 'home_departments' => [$this->f->department], 'about_text' => '  Family run since 1990.  ']);
    expect($store->featured_mode)->toBe('newest')->and($store->home_departments)->toBe([$this->f->department])->and($store->about_text)->toBe('Family run since 1990.');
});

test('best sellers never count another business\'s sales', function () {
    $mine = $this->f->product('Mine', ['created_at' => '2026-01-01 00:00:00']);
    $other = OnlineStoreFixtures::make('Other Shop', 'other-shop');
    $other->product('Theirs');
    ($this->sold)($mine, '1', $this->f->leeds->id);
    DB::table('rpt_product_daily')->insert(['company_id' => $other->company->id, 'branch_id' => $this->f->leeds->id, 'trading_day' => '2026-10-30',
        'register_id' => 'X', 'product_id' => $mine, 'qty' => '0', 'refund_qty' => '5', 'net' => '0', 'rebuilt_at' => now()]);

    $this->get(($this->url)('store.home'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('featuredMode', 'bestSellers')->where('featured.0.name', 'Mine')
        ->where('featured', fn ($items) => ! collect($items)->pluck('name')->contains('Theirs')));
});

test('on offer: products with a live till offer for everyone at the shop, with the offer label on every card', function () {
    $beans = $this->f->product('Baked beans');
    $crisps = $this->f->product('Crisps');
    $coupon = $this->f->product('Coupon only');
    $this->f->product('Plain');
    $rule = fn (array $o) => DB::table('promotion_rules')->insert([
        'id' => Ulid::new(), 'company_id' => $this->f->company->id, 'name' => 'Offer', 'type' => 'multiBuy', 'scope' => 'product',
        'buy_quantity' => 3, 'deal_price' => '2.00', 'effective_from' => '2026-10-01', 'is_active' => true, 'row_version' => 1, ...$o,
    ]);
    $rule(['target_id' => $beans]);
    $rule(['target_id' => $crisps, 'effective_to' => '2026-10-31']);  // ended
    $rule(['target_id' => $coupon, 'requires_coupon' => true, 'coupon_code' => 'X']);
    ($this->sections)(['hero', 'offers']);

    $this->get(($this->url)('store.home'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('sections', ['hero', 'offers'])
        ->where('offers', fn ($items) => collect($items)->pluck('name')->all() === ['Baked beans'])
        ->where('offers.0.offer', '3 for £2.00'));

    $this->get(($this->url)('store.search'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('products', fn ($items) => collect($items)->pluck('offer', 'name')->all() === ['Baked beans' => '3 for £2.00', 'Coupon only' => null, 'Crisps' => null, 'Plain' => null]));
});

test('department rows: the chosen departments shown at the shop, else the first three', function () {
    $this->f->product('Beans');
    $fresh = OnlineStoreFixtures::department($this->f->company, 'Fresh', 2);
    $this->f->product('Apples', ['department_id' => $fresh]);
    ($this->sections)(['departmentRows', 'departments']);

    $this->get(($this->url)('store.home'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('departmentRows', fn ($rows) => collect($rows)->pluck('name')->all() === ['Grocery', 'Fresh'])
        ->has('departmentTiles', 2));

    app(SaveOnlineStore::class)->handle($this->f->company, ['slug' => 'kirkgate', 'home_departments' => [$fresh],
        'sections' => [['key' => 'departmentRows', 'enabled' => true]]]);

    $this->get(($this->url)('store.home'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('departmentRows', fn ($rows) => collect($rows)->pluck('name')->all() === ['Fresh'])
        ->where('departmentRows.0.products.0.name', 'Apples'));
});

test('photos: the online photo, else the master catalogue picture of a barcode, else none; departments get one too', function () {
    $own = $this->f->product('Own photo');
    $master = $this->f->product('Master photo');
    $none = $this->f->product('No photo');
    app(SetOnlineProduct::class)->handle($this->f->company, $own, ['photo_path' => 'online-store/x/products/own.jpg']);
    DB::table('product_barcodes')->insert(['id' => Ulid::new(), 'company_id' => $this->f->company->id, 'product_id' => $master, 'barcode' => '5000157024671', 'row_version' => 1]);
    DB::table('master_products')->insert(['id' => strtolower(Ulid::new()), 'barcode' => '5000157024671', 'name' => 'Beans', 'image_url' => 'https://images.example.test/beans.jpg',
        'source' => 'admin', 'age_rule' => 'none']);

    $this->get(($this->url)('store.search'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('products', fn ($items) => collect($items)->pluck('photoUrl', 'name')->all() === [
            'Master photo' => 'https://images.example.test/beans.jpg', 'No photo' => null, 'Own photo' => Storage::disk('public')->url('online-store/x/products/own.jpg'),
        ]));

    $this->get(($this->url)('store.home'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('departmentTiles.0.photoUrl', Storage::disk('public')->url('online-store/x/products/own.jpg')));
    expect($none)->not->toBeEmpty();
});

test('product page: pack size and unit price, allergens from the shop or the tills, before purchase', function () {
    $beans = $this->f->product('Baked beans', ['net_mass_kg' => '0.4150', 'sell_price' => '1.10']);
    $cake = $this->f->product('Cake');

    $this->get(($this->url)('store.product', ['product' => $beans]))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('product.size', '415 g')->where('product.unitPrice', ['amount' => '2.65', 'unit' => 'kg'])->where('allergens', null));

    DB::table('product_allergen_matrices')->insert(['id' => Ulid::new(), 'company_id' => $this->f->company->id, 'product_id' => $cake,
        'allergens_csv' => 'milk,eggs,cerealsContainingGluten', 'notes' => 'Made where nuts are used.', 'row_version' => 1]);
    $this->get(($this->url)('store.product', ['product' => $cake]))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('allergens', 'Contains: Milk, Eggs, Cereals containing gluten. Made where nuts are used.'));

    app(SetOnlineProduct::class)->handle($this->f->company, $cake, ['allergen_info' => 'Ingredients: flour (wheat), butter (milk), eggs.']);
    $this->get(($this->url)('store.product', ['product' => $cake]))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('allergens', 'Ingredients: flour (wheat), butter (milk), eggs.'));
});

test('the head is written on the server: title, description, Open Graph, canonical, robots and JSON-LD', function () {
    $beans = $this->f->product('Baked beans', ['sell_price' => '1.10', 'description' => 'Beans in tomato sauce.']);

    $this->get(($this->url)('store.home'))->assertOk()->assertHeaderMissing('X-Robots-Tag')
        ->assertSee('<meta name="robots" content="index, follow">', false)
        ->assertSee('<meta property="og:title" content="Kirkgate Convenience · Order online, click &amp; collect">', false)
        ->assertSee('<link rel="canonical" href="'.url('/store/kirkgate').'">', false)
        ->assertSee('"@type":"GroceryStore"', false);

    $this->get(($this->url)('store.product', ['product' => $beans]))
        ->assertSee('<title inertia>Baked beans · Kirkgate Convenience</title>', false)
        ->assertSee('<meta name="description" content="Beans in tomato sauce.">', false)
        ->assertSee('"@type":"Product"', false)->assertSee('"priceCurrency":"GBP"', false)->assertSee('"price":"1.10"', false)
        ->assertSee('https://schema.org/InStock', false);

    $this->get(($this->url)('store.basket'))->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('content="noindex, nofollow"', false);
    $this->get(($this->url)('store.checkout'))->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $this->get(($this->url)('store.search', ['q' => 'beans']))->assertHeader('X-Robots-Tag', 'noindex, follow');
    $this->get(($this->url)('store.department', ['department' => $this->f->department, 'sort' => 'priceAsc']))->assertHeader('X-Robots-Tag', 'noindex, follow');
    $this->get(($this->url)('store.product', ['product' => Ulid::new()]))->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('sitemap.xml lists home, departments and products shown; robots.txt keeps private pages out; never another business\'s', function () {
    $beans = $this->f->product('Baked beans');
    $hidden = $this->f->product('Hidden');
    app(SetOnlineProduct::class)->handle($this->f->company, $hidden, ['hidden' => true]);
    $theirs = OnlineStoreFixtures::make('Other Shop', 'other-shop')->product('Theirs');

    $this->get(($this->url)('store.sitemap'))->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee(url('/store/kirkgate/product/'.$beans), false)
        ->assertSee(url('/store/kirkgate/department/'.$this->f->department), false)
        ->assertDontSee($hidden, false)->assertDontSee($theirs, false);

    $this->get(($this->url)('store.robots'))->assertOk()
        ->assertSee('Disallow: /store/kirkgate/checkout', false)
        ->assertSee('Disallow: /store/kirkgate/order/', false)
        ->assertSee('Sitemap: '.url('/store/kirkgate/sitemap.xml'), false);
});

test('colours: the frame carries a readable palette from the store colour, also on <html>', function () {
    OnlineStore::withoutCompanyScope()->whereKey($this->f->store->id)->update(['brand_colour' => '#F5C518']);

    $this->get(($this->url)('store.home'))
        ->assertSee('--color-on-brand: #0E1712', false)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('store.palette.brand', '#F5C518')
            ->where('store.palette.onBrand', '#0E1712')
            ->where('store.palette.brandText', fn ($hex) => BrandPalette::contrast($hex, '#FFFFFF') >= 4.5));
});

test('the about text and the shop\'s open-now line reach the page', function () {
    app(SaveOnlineStore::class)->handle($this->f->company, ['slug' => 'kirkgate', 'about_text' => 'Family run since 1990.']);

    $this->get(($this->url)('store.home'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('aboutText', 'Family run since 1990.')
        ->where('store.shop.openNow', ['open' => false, 'label' => 'Opens today at 08:00']));

    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00', 'UTC'));
    $this->get(($this->url)('store.home'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('store.shop.openNow', ['open' => true, 'label' => 'Open now · until 20:00']));
});
