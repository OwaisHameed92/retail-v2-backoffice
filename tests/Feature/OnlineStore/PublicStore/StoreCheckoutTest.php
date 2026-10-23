<?php

use App\Domain\OnlineStore\Actions\SaveStoreShop;
use App\Domain\OnlineStore\Actions\SetTakingOrders;
use App\Domain\OnlineStore\Events\OnlineOrderPlaced;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

const STORE_SLOT = '2026-11-02T10:00:00Z';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    $this->beans = $this->f->product('Baked beans', ['sell_price' => '1.10']);
    $this->mince = $this->f->product('Lamb mince', ['sell_price' => '9.80', 'is_weighed' => true, 'unit_code' => 'kg']);
    $this->url = fn (string $name, array $params = []) => route($name, ['slug' => 'kirkgate', ...$params], false);
    $this->add = fn (string $product, string $quantity) => $this->post(($this->url)('store.basket.add'), ['product' => $product, 'quantity' => $quantity]);
    $this->details = fn (array $overrides = []) => [
        'shopId' => $this->f->shop->id, 'collectFrom' => STORE_SLOT, 'customerName' => 'Asha Patel', 'customerMobile' => '07700 900123',
        'customerEmail' => 'Asha@Example.test', ...$overrides,
    ];
    $this->place = fn (array $overrides = []) => $this->from(($this->url)('store.checkout'))->post(($this->url)('store.checkout.place'), ($this->details)($overrides));
    $this->orders = fn () => DB::table('online_orders')->count();
});

test('checkout shows the shop, days and slots of the first day with room, and the basket', function () {
    ($this->add)($this->beans, '2');

    $this->get(($this->url)('store.checkout'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('store/checkout')
        ->where('shop.name', 'Leeds')->where('shops', [])
        ->where('day', '2026-11-02')->has('days', 7)->where('days.0.label', 'Today')->where('days.1.label', 'Tomorrow')
        ->where('slots.0', ['from' => '2026-11-02T08:00:00Z', 'to' => '2026-11-02T08:30:00Z', 'label' => '08:00–08:30', 'taken' => 0, 'capacity' => 2, 'available' => true])
        ->where('lines.0.lineTotal', '2.20')->where('honeypot', 'website'));

    $this->get(($this->url)('store.checkout', ['day' => '2026-11-04']))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('day', '2026-11-04')->where('slots.0.label', '08:00–08:30'));
    $this->get(($this->url)('store.checkout', ['day' => '2027-01-01']))->assertInertia(fn (AssertableInertia $page) => $page->where('day', '2026-11-02'));
});

test('happy path: the order is placed from the session basket, the basket empties, the signed order page confirms it', function () {
    Event::fake([OnlineOrderPlaced::class]);
    ($this->add)($this->beans, '3');
    ($this->add)($this->mince, '1.25');

    $response = ($this->place)();

    $order = OnlineOrder::withoutCompanyScope()->sole();
    expect($order->reference)->toBe('W-0001')
        ->and($order->items_total)->toBe('15.55')
        ->and($order->customer_email)->toBe('asha@example.test')
        ->and($order->collect_from->toIso8601ZuluString())->toBe(STORE_SLOT)
        ->and(app(CurrentCompany::class)->has())->toBeFalse();
    $location = (string) $response->headers->get('Location');
    expect($location)->toContain("/store/kirkgate/order/{$order->id}?signature=");
    $response->assertRedirect($location);
    expect(session('online_store.'.$this->f->store->id)['lines'])->toBe([]);
    Event::assertDispatched(OnlineOrderPlaced::class);

    $this->get($location)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('store/order')
        ->where('justPlaced', true)->where('order.reference', 'W-0001')->where('order.estimated', true)
        ->where('order.lines.1', ['name' => 'Lamb mince', 'quantity' => '1.25', 'isWeighed' => true, 'unitPrice' => '9.80', 'lineTotal' => '12.25'])
        ->where('basket.count', 0));
    // The confirmation is shown once; the link keeps working as the order page.
    $this->get($location)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('justPlaced', false));
});

test('prices and lines come from the session basket and the catalogue, never the request', function () {
    ($this->add)($this->beans, '2');

    ($this->place)(['lines' => [['productId' => $this->mince, 'quantity' => '40']], 'items_total' => '0.01', 'price' => '0.01'])->assertRedirect();

    $order = OnlineOrder::withoutCompanyScope()->sole();
    expect($order->items_total)->toBe('2.20')
        ->and(DB::table('online_order_lines')->where('online_order_id', $order->id)->pluck('name')->all())->toBe(['Baked beans']);
});

test('a full slot is refused and nothing is placed; another slot takes it', function () {
    $this->f->order([['productId' => $this->beans, 'quantity' => '1']], STORE_SLOT);
    $this->f->order([['productId' => $this->beans, 'quantity' => '1']], STORE_SLOT);
    ($this->add)($this->beans, '1');

    $this->get(($this->url)('store.checkout'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('slots', fn ($slots) => collect($slots)->firstWhere('from', STORE_SLOT)['available'] === false));
    ($this->place)()->assertRedirect(($this->url)('store.checkout'))
        ->assertSessionHasErrors(['collectFrom' => 'That collection time is now full. Choose another one.']);
    expect(($this->orders)())->toBe(2);

    ($this->place)(['collectFrom' => '2026-11-02T10:30:00Z'])->assertRedirect();
    expect(($this->orders)())->toBe(3);
});

test('a line that stopped being orderable refuses the order and leaves the cleaned basket to confirm', function () {
    $crisps = $this->f->product('Crisps', ['track_stock' => true]);
    $this->f->stock($this->f->leeds, $crisps, '3');
    ($this->add)($this->beans, '1');
    ($this->add)($crisps, '1');
    DB::table('branch_products')->where('product_id', $crisps)->update(['qty_on_hand' => '0']);

    ($this->place)()->assertSessionHasErrors(['lines' => 'Your basket changed: Crisps has just sold out, so we took it out of your basket. Check it and place the order again.']);
    expect(($this->orders)())->toBe(0)
        ->and(array_keys(session('online_store.'.$this->f->store->id)['lines']))->toBe([$this->beans]);

    ($this->place)()->assertRedirect();
    expect(($this->orders)())->toBe(1);
});

test('the honeypot, missing details and an empty basket place nothing (422 for JSON callers)', function () {
    ($this->add)($this->beans, '1');

    $this->postJson(($this->url)('store.checkout.place'), ($this->details)(['website' => 'https://spam.example']))
        ->assertUnprocessable()->assertJsonValidationErrors(['website' => 'We could not place the order. Refresh the page and try again.']);
    $this->postJson(($this->url)('store.checkout.place'), ($this->details)(['customerName' => '', 'customerMobile' => 'call me', 'collectFrom' => '']))
        ->assertUnprocessable()->assertJsonValidationErrors(['customerName', 'collectFrom']);
    $this->postJson(($this->url)('store.checkout.place'), ($this->details)(['customerMobile' => 'call me']))
        ->assertUnprocessable()->assertJsonValidationErrors(['customerMobile' => 'Enter a mobile number we can reach you on.']);
    expect(($this->orders)())->toBe(0);

    $this->delete(($this->url)('store.basket.remove', ['product' => $this->beans]));
    ($this->place)()->assertSessionHasErrors(['lines' => 'Your basket is empty.']);
    expect(($this->orders)())->toBe(0);
});

test('not taking orders: checkout refuses to place', function () {
    ($this->add)($this->beans, '1');
    app(SetTakingOrders::class)->handle($this->f->company, false);

    $this->get(($this->url)('store.checkout'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('store.takingOrders', false));
    ($this->place)()->assertSessionHasErrors(['store' => 'This store is not taking orders right now.']);
    expect(($this->orders)())->toBe(0);
});

test('placing needs the CSRF token (419) and is rate limited (429)', function () {
    ($this->add)($this->beans, '1');
    config(['online_store.checkout_per_minute' => 2]);

    ($this->place)(['customerName' => ''])->assertSessionHasErrors('customerName');
    ($this->place)(['customerName' => ''])->assertSessionHasErrors('customerName');
    ($this->place)()->assertStatus(429)->assertInertia(fn (AssertableInertia $page) => $page->component('store/error')->where('status', 429));
    expect(($this->orders)())->toBe(0);

    $this->app->instance(ValidateCsrfToken::class, new class(app(), app('encrypter')) extends ValidateCsrfToken
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });
    $this->postJson(route('store.checkout.place', ['slug' => 'kirkgate'], false), ($this->details)())->assertStatus(419);
});

test('with two shops the checkout offers both and the order goes to the one chosen', function () {
    app(SaveStoreShop::class)->handle($this->f->company, $this->f->bradford->id, OnlineStoreFixtures::shopInput());
    ($this->add)($this->beans, '1');

    $this->get(($this->url)('store.checkout'))->assertInertia(fn (AssertableInertia $page) => $page->has('shops', 2)
        ->where('shops.0.name', 'Bradford')->where('shops.1.name', 'Leeds'));

    ($this->place)(['shopId' => $this->f->shop->id])->assertRedirect();
    expect(OnlineOrder::withoutCompanyScope()->sole()->branch_id)->toBe($this->f->leeds->id);
});
