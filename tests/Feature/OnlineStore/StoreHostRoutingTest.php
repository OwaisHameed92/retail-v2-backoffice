<?php

use App\Domain\OnlineStore\Support\StoreLinks;
use Carbon\CarbonImmutable;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

/*
| Audit OSA-8: with ONLINE_STORE_HOST set, the store's host answers only the store (and its card callback and /up);
| the portal, admin, sign-in and APIs answer 404 there. Without a host, nothing changes.
*/

const STORE_HOST = 'shops.example.test';

/** Loads every route file again as bootstrap/app.php does (store first), now with a store host. */
function loadRoutesWithStoreHost(): void
{
    config(['online_store.host' => STORE_HOST]);
    $routes = new RouteCollection;
    app('router')->setRoutes($routes);
    app('url')->setRoutes($routes);
    Route::middleware('web')->group(base_path('routes/store.php'));
    Route::middleware('web')->group(base_path('routes/web.php'));
    Route::middleware('api')->prefix('api')->group(base_path('routes/api.php'));
    Route::middleware('web')->prefix('admin')->name('admin.')->group(base_path('routes/admin.php'));
    Route::middleware('web')->prefix('app')->name('app.')->group(base_path('routes/app.php'));
    Route::group([], base_path('routes/webhooks.php'));
    // The framework's health route (bootstrap/app.php `health: '/up'`) is not in a route file: a stand-in.
    Route::get('up', fn () => response('ok'));
    $routes->refreshNameLookups();
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->withoutVite();
    $this->f = OnlineStoreFixtures::make();
    $this->beans = $this->f->product('Baked beans', ['sell_price' => '1.10']);
});

test('host mode: the store host serves the store, its order links and its card callback', function () {
    loadRoutesWithStoreHost();
    $host = 'http://'.STORE_HOST;

    $this->get("{$host}/kirkgate")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('store/home'));
    $this->get("{$host}/kirkgate/product/{$this->beans}")->assertOk();
    $this->get("{$host}/kirkgate/basket")->assertOk();
    $this->get("{$host}/kirkgate/sitemap.xml")->assertOk();
    $this->get("{$host}/")->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page->component('store/error'));

    $placed = $this->f->order([['productId' => $this->beans, 'quantity' => '1']], '2026-11-02T10:00:00Z');
    $this->get(app(StoreLinks::class)->orderPage($placed->order))->assertOk();

    // The DNA callback reaches its controller (which answers with an outcome), not the store host's 404.
    $this->postJson("{$host}/webhooks/dna/{$this->f->store->id}", [])->assertJsonStructure(['received', 'outcome']);
    $this->get("{$host}/up")->assertOk();
});

test('host mode: portal, admin, sign-in, APIs and unknown addresses are the store\'s 404 on the store host', function (string $path) {
    loadRoutesWithStoreHost();

    $this->get('http://'.STORE_HOST.$path)->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('store/error'));
})->with([
    'admin sign-in' => '/admin/login',
    'admin' => '/admin',
    'portal' => '/app',
    'portal orders' => '/app/online-orders',
    'portal store builder' => '/app/online-store',
    'portal sign-in' => '/login',
    'register' => '/register',
    'password reset' => '/forgot-password',
    'unknown deep path' => '/a/b/c/d',
]);

test('host mode: APIs, JSON callers and other webhooks are refused on the store host', function () {
    loadRoutesWithStoreHost();
    $host = 'http://'.STORE_HOST;

    // api/*: the API's own error body; on a till endpoint the contract's "the portal does not offer this call".
    $this->getJson("{$host}/api/v1/sync/hello")->assertStatus(400)->assertJsonPath('code', 'request.invalid');
    $this->get("{$host}/api/v1/sync/hello")->assertStatus(400)->assertJsonPath('code', 'request.invalid');
    $this->postJson("{$host}/webhooks/gocardless", [])->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    $this->postJson("{$host}/admin/login", ['email' => 'a@b.test', 'password' => 'x'])->assertNotFound();
});

test('host mode: the app host still serves the portal, admin and APIs', function () {
    loadRoutesWithStoreHost();

    $this->get('/admin/login')->assertOk();
    $this->get('/login')->assertOk();
    $this->get('/app')->assertRedirect(route('login'));
    expect($this->getJson('/api/v1/sync/hello')->status())->not->toBe(404);
    // The store is not on the app host when it has its own.
    $this->get('/store/kirkgate')->assertNotFound();
});

test('prefix mode (no store host): the store lives under /store/{slug} and the portal and admin answer as before', function () {
    expect(config('online_store.host'))->toBeNull();

    $this->get('/store/kirkgate')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('store/home'));
    $this->get("/store/kirkgate/product/{$this->beans}")->assertOk();
    $this->get('/admin/login')->assertOk();
    $this->get('/login')->assertOk();
    $this->get('/app')->assertRedirect(route('login'));
    $this->get('/up')->assertOk();
});

test('a store host set but the request on another host: nothing is blocked', function () {
    // Routes stay in prefix mode; only the host check runs.
    config(['online_store.host' => STORE_HOST]);

    $this->get('/admin/login')->assertOk();
    $this->get('/up')->assertOk();
    $this->get('http://'.STORE_HOST.'/up')->assertOk();
    $this->get('http://'.STORE_HOST.'/admin/login')->assertNotFound();
});
