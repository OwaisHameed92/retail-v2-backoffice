<?php

use App\Domain\OnlineStore\Actions\SetOnlineProduct;
use App\Domain\OnlineStore\Actions\SetStoreLive;
use App\Domain\OnlineStore\Actions\SetTakingOrders;
use App\Domain\OnlineStore\Support\StoreLinks;
use App\Domain\Tenancy\Enums\CompanyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    $this->beans = $this->f->product('Baked beans', ['sell_price' => '1.10']);
    $this->mince = $this->f->product('Lamb mince', ['sell_price' => '9.80', 'is_weighed' => true, 'unit_code' => 'kg']);
    $this->url = fn (string $name, array $params = []) => route($name, ['slug' => 'kirkgate', ...$params], false);
});

test('every store page answers in the store\'s own frame', function (string $name, Closure $params, string $component) {
    $this->get(($this->url)($name, $params($this)))
        ->assertOk()
        ->assertViewIs('store')
        ->assertInertia(fn (AssertableInertia $page) => $page->component($component)
            ->where('store.name', 'Kirkgate Convenience')
            ->where('store.brand', '#0B4A34')
            ->where('store.takingOrders', true)
            ->where('store.shop.name', 'Leeds')
            ->where('store.departments.0.name', 'Grocery')
            ->where('basket.count', 0));
})->with([
    'home' => ['store.home', fn () => [], 'store/home'],
    'department' => ['store.department', fn ($t) => ['department' => $t->f->department], 'store/department'],
    'search' => ['store.search', fn () => ['q' => 'beans'], 'store/search'],
    'product' => ['store.product', fn ($t) => ['product' => $t->beans], 'store/product'],
    'basket' => ['store.basket', fn () => [], 'store/basket'],
    'checkout' => ['store.checkout', fn () => [], 'store/checkout'],
]);

test('home: sections in the store\'s order, default banner, featured products and the next collection', function () {
    $this->f->store->forceFill(['sections' => [
        ['key' => 'featured', 'enabled' => true], ['key' => 'hero', 'enabled' => true], ['key' => 'about', 'enabled' => false],
        ['key' => 'shops', 'enabled' => true],
    ], 'brand_colour' => '#1f4e9c', 'headline' => 'Fresh every morning'])->save();

    $this->get(($this->url)('store.home'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('sections', ['featured', 'hero', 'shops'])
        ->where('headline', 'Fresh every morning')
        ->where('bannerUrl', asset('images/online-store/hero-grocery.jpg'))
        ->where('nextCollection', 'Today 08:00–08:30')
        ->where('store.brand', '#1F4E9C')
        ->where('featured', fn ($items) => collect($items)->pluck('name')->all() === ['Baked beans', 'Lamb mince'])
        ->where('shops.0.name', 'Leeds'));
});

test('product photos come only from the online photo, never the till\'s local image path', function () {
    $till = $this->f->product('Crisps', ['image_path' => 'C:\\ProgramData\\SSPOS\\product-images\\crisps.jpg']);
    app(SetOnlineProduct::class)->handle($this->f->company, $this->beans, ['photo_path' => 'online/beans.jpg']);

    $this->get(($this->url)('store.search'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('products', fn ($items) => collect($items)->pluck('photoUrl', 'id')->all() === [
            $this->beans => Storage::disk('public')->url('online/beans.jpg'), $till => null, $this->mince => null,
        ]));
});

test('department and search: sort by price, in stock only, price range and pages', function () {
    $this->f->product('Apples', ['sell_price' => '2.50', 'track_stock' => true]);

    $this->get(($this->url)('store.department', ['department' => $this->f->department, 'sort' => 'priceDesc']))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('store/department')
            ->where('products', fn ($items) => collect($items)->pluck('name')->all() === ['Lamb mince', 'Apples', 'Baked beans'])
            ->where('filters', ['sort' => 'priceDesc', 'stock' => false, 'min' => null, 'max' => null])
            ->where('pagination', ['page' => 1, 'lastPage' => 1, 'total' => 3]));

    $this->get(($this->url)('store.search', ['stock' => '1', 'min' => '1', 'max' => '5', 'sort' => 'bogus']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products', fn ($items) => collect($items)->pluck('name')->all() === ['Baked beans'])
            ->where('filters.sort', 'name')->where('filters.min', '1.00')->where('filters.max', '5.00'));

    $this->get(($this->url)('store.search', ['q' => 'mince']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('term', 'mince')->where('pagination.total', 1)
            ->where('products.0.isWeighed', true)->where('products.0.step', '0.25'));
});

test('a department with nothing online, an unknown product and another business\'s product are the store\'s 404', function () {
    $other = OnlineStoreFixtures::make('Briggate Butchers', 'briggate');
    $steak = $other->product('Rump steak');
    $empty = OnlineStoreFixtures::department($this->f->company, 'Empty', 2);

    foreach ([
        ($this->url)('store.department', ['department' => $empty]),
        ($this->url)('store.department', ['department' => $other->department]),
        ($this->url)('store.product', ['product' => $steak]),
        ($this->url)('store.product', ['product' => '01K5T0Q8C4000000000000P999']),
    ] as $url) {
        $this->get($url)->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page->component('store/error')
            ->where('status', 404)->where('store.name', 'Kirkgate Convenience'));
    }
});

test('unknown, unpublished, suspended or switched-off stores are a plain 404 without any business data', function (Closure $close) {
    $close($this);

    $this->get(($this->url)('store.home'))->assertNotFound()->assertViewIs('store')
        ->assertInertia(fn (AssertableInertia $page) => $page->component('store/error')->where('status', 404)->missing('store')->missing('basket'));
    $this->post(($this->url)('store.basket.add'), ['product' => $this->beans, 'quantity' => '1'])->assertNotFound();
})->with([
    'unknown slug' => [fn ($t) => $t->url = fn (string $name) => route($name, ['slug' => 'nobody-here'], false)],
    'not live' => [fn ($t) => app(SetStoreLive::class)->handle($t->f->company, false)],
    'switch off' => [fn () => config(['online_store.enabled' => false])],
    'suspended' => [fn ($t) => $t->f->company->forceFill(['status' => CompanyStatus::Suspended])->save()],
]);

test('Pakistan: the store is not online', function () {
    OnlineStoreFixtures::asPakistan();

    $this->get(($this->url)('store.home'))->assertNotFound();
})->group('country-pk');

test('live but not taking orders: browsable, adding and checkout refused with a clear message', function () {
    app(SetTakingOrders::class)->handle($this->f->company, false);

    $this->get(($this->url)('store.product', ['product' => $this->beans]))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('store.takingOrders', false)->where('nextCollection', null));
    $this->from(($this->url)('store.home'))->post(($this->url)('store.basket.add'), ['product' => $this->beans, 'quantity' => '1'])
        ->assertRedirect(($this->url)('store.home'))
        ->assertSessionHasErrors(['product' => 'This shop is not taking online orders right now.']);
    expect(session('online_store.'.$this->f->store->id))->toBeNull();
});

test('the order page needs its signed link and only shows this store\'s order', function () {
    $placed = $this->f->order([['productId' => $this->beans, 'quantity' => '2']], '2026-11-02T10:00:00Z');
    $other = OnlineStoreFixtures::make('Briggate Butchers', 'briggate');
    $theirs = $other->order([['productId' => $other->product('Rump steak'), 'quantity' => '1']], '2026-11-02T10:00:00Z');
    $signed = app(StoreLinks::class)->orderPage($placed->order);

    $this->get($signed)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('store/order')
        ->where('order.reference', 'W-0001')->where('order.status', 'new')->where('order.itemsTotal', '2.20')
        ->where('order.collect', 'Today 10:00–10:30')->where('order.steps.0.current', true)
        ->where('shop.name', 'Leeds')->where('justPlaced', false));

    $this->get(($this->url)('store.order', ['order' => $placed->order->id]))->assertForbidden()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('store/error')->where('status', 403));
    $this->get($signed.'x')->assertForbidden();

    // A valid signature for another business's order under this store's address: not found here.
    $this->get(URL::signedRoute('store.order', ['slug' => 'kirkgate', 'order' => $theirs->order->id]))->assertNotFound();
    // And that business's own link opens it on its own store only.
    $this->get(app(StoreLinks::class)->orderPage($theirs->order))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('store.name', 'Briggate Butchers')->where('order.reference', 'W-0001'));
});

test('without a store host the store lives under /store/{slug}; with one, at https://{host}/{slug}', function () {
    expect(route('store.home', ['slug' => 'kirkgate']))->toBe(url('/store/kirkgate'));
    $this->get('/store/kirkgate')->assertOk();

    // Load the web routes again as bootstrap/app.php does (store first), now with a store host.
    config(['online_store.host' => 'shops.example.test']);
    $routes = new RouteCollection;
    app('router')->setRoutes($routes);
    app('url')->setRoutes($routes);
    Route::middleware('web')->group(base_path('routes/store.php'));
    Route::middleware('web')->group(base_path('routes/web.php'));
    $routes->refreshNameLookups();

    $hostAndPath = fn (string $url) => parse_url($url, PHP_URL_HOST).parse_url($url, PHP_URL_PATH);
    expect($hostAndPath(route('store.home', ['slug' => 'kirkgate'])))->toBe('shops.example.test/kirkgate')
        ->and($hostAndPath(route('store.product', ['slug' => 'kirkgate', 'product' => $this->beans])))->toBe("shops.example.test/kirkgate/product/{$this->beans}");

    $this->get('http://shops.example.test/kirkgate')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('store/home'));
    $this->get("http://shops.example.test/kirkgate/product/{$this->beans}")->assertOk();
    $this->get('http://shops.example.test/')->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page->component('store/error'));
    $this->get('http://shops.example.test/nobody-here')->assertNotFound();
    // Portal pages are not served on the store host's store paths: /login there is a store address, not the sign-in.
    $this->get('http://shops.example.test/login')->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page->component('store/error'));

    $placed = $this->f->order([['productId' => $this->beans, 'quantity' => '1']], '2026-11-02T10:00:00Z');
    $link = app(StoreLinks::class)->orderPage($placed->order);
    expect($hostAndPath($link))->toBe("shops.example.test/kirkgate/order/{$placed->order->id}");
    $this->get($link)->assertOk();
});
