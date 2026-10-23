<?php

use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Support\StoreLinks;
use App\Domain\Tenancy\CurrentCompany;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->a = OnlineStoreFixtures::make('Kirkgate Convenience', 'kirkgate');
    $this->b = OnlineStoreFixtures::make('Briggate Butchers', 'briggate');
    $this->aBeans = $this->a->product('A beans');
    $this->bSteak = $this->b->product('B steak');
    $this->bDepartment = OnlineStoreFixtures::department($this->b->company, 'B meat', 2);
    DB::table('products')->where('id', $this->bSteak)->update(['department_id' => $this->bDepartment]);
    $this->url = fn (string $name, array $params = []) => route($name, ['slug' => 'kirkgate', ...$params], false);
});

test('store A\'s pages never show business B\'s products, departments, shops or name', function (string $name, array $params) {
    $response = $this->get(($this->url)($name, $params))->assertOk();
    $json = json_encode($response->viewData('page'));

    expect($json)->toContain('Kirkgate Convenience')
        ->not->toContain('B steak')->not->toContain($this->bSteak)
        ->not->toContain('B meat')->not->toContain('Briggate')->not->toContain($this->b->shop->id)
        ->and(app(CurrentCompany::class)->has())->toBeFalse();
})->with([
    'home' => ['store.home', []],
    'search' => ['store.search', ['q' => 'steak']],
    'all products' => ['store.search', []],
    'basket' => ['store.basket', []],
    'checkout' => ['store.checkout', []],
]);

test('business B\'s product and department ids are not found under store A', function () {
    $this->get(($this->url)('store.product', ['product' => $this->bSteak]))->assertNotFound();
    $this->get(($this->url)('store.department', ['department' => $this->bDepartment]))->assertNotFound();
});

test('a B product id is refused by A\'s basket, and one smuggled into the session is priced out', function () {
    $this->post(($this->url)('store.basket.add'), ['product' => $this->bSteak, 'quantity' => '1'])
        ->assertSessionHasErrors(['product' => 'That product is not available.']);
    expect(session('online_store.'.$this->a->store->id))->toBeNull();

    $this->withSession(['online_store.'.$this->a->store->id => ['lines' => [
        $this->aBeans => ['qty' => '1.0000', 'name' => 'A beans'],
        $this->bSteak => ['qty' => '1.0000', 'name' => 'B steak'],
    ], 'shopId' => $this->b->shop->id]]);

    $this->get(($this->url)('store.basket'))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('lines', 1)->where('lines.0.product.id', $this->aBeans)->where('store.shop.name', 'Leeds')
        ->where('basket.notices', ['B steak is no longer available, so we took it out of your basket.']));

    // Placing with B's shop is refused too.
    $this->post(($this->url)('store.checkout.place'), [
        'shopId' => $this->b->shop->id, 'collectFrom' => '2026-11-02T10:00:00Z', 'customerName' => 'Asha', 'customerMobile' => '07700 900123',
        'customerEmail' => 'asha@example.test',
    ])->assertSessionHasErrors(['shopId' => 'Choose one of the shops listed.']);
    expect(DB::table('online_orders')->count())->toBe(0);
});

test('baskets are per store: A\'s basket never shows in B\'s store', function () {
    $this->post(($this->url)('store.basket.add'), ['product' => $this->aBeans, 'quantity' => '2'])->assertSessionHasNoErrors();

    $this->get(route('store.basket', ['slug' => 'briggate'], false))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('store.name', 'Briggate Butchers')->has('lines', 0)->where('basket.count', 0));
});

test('B\'s orders never open under A, signed or not; an order placed in A belongs to A only', function () {
    $theirs = $this->b->order([['productId' => $this->bSteak, 'quantity' => '1']], '2026-11-02T10:00:00Z')->order;

    $this->get(URL::signedRoute('store.order', ['slug' => 'kirkgate', 'order' => $theirs->id]))->assertNotFound();
    $this->get(str_replace('/briggate/', '/kirkgate/', app(StoreLinks::class)->orderPage($theirs)))->assertForbidden();

    $this->post(($this->url)('store.basket.add'), ['product' => $this->aBeans, 'quantity' => '1']);
    $this->post(($this->url)('store.checkout.place'), [
        'shopId' => $this->a->shop->id, 'collectFrom' => '2026-11-02T10:00:00Z', 'customerName' => 'Asha', 'customerMobile' => '07700 900123',
        'customerEmail' => 'asha@example.test',
    ])->assertRedirect();

    $ours = OnlineOrder::withoutCompanyScope()->where('company_id', $this->a->company->id)->sole();
    expect($ours->reference)->toBe('W-0001')->and($ours->online_store_id)->toBe($this->a->store->id)
        ->and(OnlineOrder::withoutCompanyScope()->where('company_id', $this->b->company->id)->count())->toBe(1);
});

test('a signed-in portal user of business B sees store A without any portal data of A', function () {
    $user = User::factory()->create();
    DB::table('company_user')->insert(['company_id' => $this->b->company->id, 'user_id' => $user->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);

    $page = $this->actingAs($user)->get(($this->url)('store.home'))->assertOk()->viewData('page');

    expect($page['props']['branches'])->toBe([])
        ->and($page['props']['billingNotice'])->toBeNull()
        ->and(json_encode($page['props']))->not->toContain($this->a->leeds->code);
});
