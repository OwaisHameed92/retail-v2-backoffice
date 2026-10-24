<?php

use App\Domain\OnlineStore\Actions\AnonymiseOnlineOrders;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\CheckoutChallenge;
use App\Domain\OnlineStore\Support\StoreLinks;
use App\Domain\Shared\Support\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

/*
 * The online store audit's checkout fixes (docs/audit/2026-10-24-online-store.md): fake orders (OSA-1: checkout
 * challenge, per-customer and per-store limits, one order per customer per slot), recalls (OSA-4), stock on hand
 * (OSA-12), availability dates (OSA-23), price changes (OSA-24), order links that survive an offline store or a new
 * address and expire (OSA-6, OSA-22), masked contact details and anonymising (OSA-22).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    config(['online_store.customer_limits' => true, 'online_store.checkout_per_minute' => 100]);
    $this->beans = $this->f->product('Baked beans', ['sell_price' => '1.10']);
    $this->url = fn (string $name, array $params = []) => route($name, ['slug' => 'kirkgate', ...$params], false);
    $this->add = fn (string $product, string $quantity = '1') => $this->post(($this->url)('store.basket.add'), ['product' => $product, 'quantity' => $quantity]);
    $this->place = fn (array $overrides = []) => $this->from(($this->url)('store.checkout'))->post(($this->url)('store.checkout.place'), [
        'shopId' => $this->f->shop->id, 'collectFrom' => '2026-11-02T10:00:00Z', 'customerName' => 'Asha Patel', 'customerMobile' => '07700 900123',
        'customerEmail' => 'asha@example.test', ...$overrides,
    ]);
    $this->orders = fn () => DB::table('online_orders')->count();
});

test('the checkout challenge: a solved, fresh, unused token places the order; anything else is refused (OSA-1)', function () {
    config(['online_store.checkout_challenge' => true]);
    ($this->add)($this->beans);

    $challenge = null;
    $this->get(($this->url)('store.checkout'))->assertInertia(function (AssertableInertia $p) use (&$challenge) {
        $p->where('challenge.zeros', 3)->where('challenge.token', function ($token) use (&$challenge) {
            $challenge = (string) $token;

            return true;
        });
    });
    $answer = CheckoutChallenge::solve($challenge);

    ($this->place)()->assertSessionHasErrors('challenge');
    ($this->place)(['challenge' => $challenge, 'challengeAnswer' => $answer])->assertSessionHasErrors('challenge'); // opened less than 2 s ago
    $this->travel(5)->seconds();
    ($this->place)(['challenge' => $challenge, 'challengeAnswer' => (string) ((int) $answer + 1)])->assertSessionHasErrors('challenge');
    ($this->place)(['challenge' => $challenge.'x', 'challengeAnswer' => $answer])->assertSessionHasErrors('challenge');
    expect(($this->orders)())->toBe(0);

    ($this->place)(['challenge' => $challenge, 'challengeAnswer' => $answer])->assertSessionHasNoErrors();
    expect(($this->orders)())->toBe(1);
    ($this->add)($this->beans);
    ($this->place)(['challenge' => $challenge, 'challengeAnswer' => $answer, 'collectFrom' => '2026-11-02T11:00:00Z'])->assertSessionHasErrors('challenge');
    expect(CheckoutChallenge::verify(OnlineStore::withoutCompanyScope()->where('slug', 'kirkgate')->value('id').'0', $challenge, $answer))->toBeFalse();
});

test('one customer cannot fill a slot or hold more than 3 open orders; email or mobile both count (OSA-1)', function () {
    ($this->add)($this->beans);
    ($this->place)()->assertSessionHasNoErrors();
    ($this->add)($this->beans);
    ($this->place)(['customerEmail' => 'other@example.test'])->assertSessionHasErrors(['collectFrom' => 'You already have an order at that time. Choose another time.']);

    ($this->place)(['collectFrom' => '2026-11-02T10:30:00Z', 'customerMobile' => '+44 7700 900999'])->assertSessionHasNoErrors();
    ($this->add)($this->beans);
    ($this->place)(['collectFrom' => '2026-11-02T11:00:00Z', 'customerEmail' => 'x@example.test', 'customerMobile' => '07700 900999'])->assertSessionHasNoErrors();
    ($this->add)($this->beans);
    // Asha's email matches the first two, the mobile the third: three open orders.
    ($this->place)(['collectFrom' => '2026-11-02T11:30:00Z', 'customerMobile' => '07700 900999'])->assertSessionHasErrors(['customerEmail' => 'You already have orders waiting with us. Collect or cancel one before placing another.']);
    expect(($this->orders)())->toBe(3);
});

test('a store takes at most its hourly number of orders (OSA-1)', function () {
    config(['online_store.orders_per_store_hour' => 2]);
    foreach (['a', 'b', 'c'] as $i => $who) {
        ($this->add)($this->beans);
        $response = ($this->place)(['customerEmail' => "{$who}@example.test", 'customerMobile' => '07700 90012'.$i, 'collectFrom' => '2026-11-02T1'.$i.':00:00Z']);
    }
    $response->assertSessionHasErrors(['store' => 'We are taking a lot of orders right now. Try again in a few minutes.']);
    expect(($this->orders)())->toBe(2);
});

test('the mobile must be a UK mobile (OSA-26)', function (string $mobile, bool $ok) {
    ($this->add)($this->beans);
    $response = ($this->place)(['customerMobile' => $mobile]);
    $ok ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors(['customerMobile' => 'Enter a UK mobile number, like 07700 900123.']);
})->with([
    'spaced' => ['07700 900123', true], 'international' => ['+44 7700 900123', true], 'landline' => ['0113 496 0000', false], 'ones' => ['1111111', false],
]);

test('a product under an open recall at the shop is not sold online (OSA-4)', function () {
    $recall = Ulid::new();
    DB::table('product_recalls')->insert(['id' => $recall, 'company_id' => $this->f->company->id, 'product_id' => $this->beans, 'reason' => 'FSA recall', 'row_version' => 1]);

    ($this->add)($this->beans)->assertSessionHasErrors(['product' => 'That product is not available.']);

    DB::table('product_recall_branch_states')->insert(['id' => Ulid::new(), 'company_id' => $this->f->company->id, 'branch_id' => $this->f->leeds->id,
        'recall_id' => $recall, 'status' => 'closed', 'row_version' => 1]);
    ($this->add)($this->beans)->assertSessionHasNoErrors();
});

test('availability dates and seasonal events keep a product off line outside its window (OSA-23)', function () {
    $later = $this->f->product('Mince pies', ['availability_from' => '2026-12-01']);
    $over = $this->f->product('Easter eggs', ['availability_to' => '2026-04-30T23:59:59Z']);
    $event = Ulid::new();
    DB::table('seasonal_events')->insert(['id' => $event, 'company_id' => $this->f->company->id, 'name' => 'Diwali', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-10', 'is_active' => true, 'row_version' => 1]);
    $season = $this->f->product('Diwali sweets', ['availability_seasonal_event_id' => $event]);
    $time = $this->f->product('Breakfast roll', ['availability_from' => '07:00', 'availability_to' => '11:00']);

    ($this->add)($later)->assertSessionHasErrors('product');
    ($this->add)($over)->assertSessionHasErrors('product');
    ($this->add)($season)->assertSessionHasNoErrors();
    ($this->add)($time)->assertSessionHasNoErrors();

    $this->travelTo(CarbonImmutable::parse('2026-11-12 07:00:00', 'UTC'));
    ($this->add)($season)->assertSessionHasErrors('product');
});

test('stock on hand caps the quantity, less what open online orders already hold (OSA-12)', function () {
    $crisps = $this->f->product('Crisps', ['track_stock' => true]);
    $this->f->stock($this->f->leeds, $crisps, '3');

    ($this->add)($crisps, '4')->assertSessionHasErrors(['quantity' => 'Only 3 of Crisps left. Change the quantity.']);
    ($this->add)($crisps, '2')->assertSessionHasNoErrors();
    ($this->place)()->assertSessionHasNoErrors();

    ($this->add)($crisps, '2')->assertSessionHasErrors(['quantity' => 'Only 1 of Crisps left. Change the quantity.']);
    ($this->add)($crisps, '1')->assertSessionHasNoErrors();
});

test('a price that changed since the customer saw it is told before the order is placed (OSA-24)', function () {
    ($this->add)($this->beans, '2');
    DB::table('products')->where('id', $this->beans)->update(['sell_price' => '1.30']);

    ($this->place)()->assertSessionHasErrors(['lines' => 'Your basket changed: The price of Baked beans changed from £1.10 to £1.30. Check it and place the order again.']);
    expect(($this->orders)())->toBe(0);
    ($this->place)()->assertSessionHasNoErrors();
    expect(OnlineOrder::withoutCompanyScope()->sole()->items_total)->toBe('2.60');
});

test('the order link keeps working offline and after the address changes, masks the contact details and expires (OSA-6, OSA-22)', function () {
    $order = $this->f->order([['productId' => $this->beans, 'quantity' => '1']], '2026-11-02T10:00:00Z')->order;
    $link = app(StoreLinks::class)->orderPage($order);

    OnlineStore::withoutCompanyScope()->whereKey($this->f->store->id)->update(['is_live' => false, 'slug' => 'kirkgate-new']);
    $this->get($link)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('store/order')
        ->where('order.reference', 'W-0001')->where('order.customerMobile', '07••• •••123')->where('order.customerEmail', 'a•••@example.test')
        ->where('store.urls.home', '/store/kirkgate-new'));
    $this->get($link)->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    // (07700 900123 is also the country's example number, so only the email is searched for.)
    expect($this->get($link)->getContent())->not->toContain('asha@example.test');

    $this->travelTo(CarbonImmutable::parse('2027-01-02 10:00:00', 'UTC'));
    $this->get($link)->assertForbidden()->assertInertia(fn (AssertableInertia $p) => $p->component('store/error')->where('title', 'This link has expired'));

    OnlineStore::withoutCompanyScope()->whereKey($this->f->store->id)->update(['addon_on' => false]);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC'));
    $this->get($link)->assertNotFound();
});

test('customer details are removed from orders finished long ago (OSA-22)', function () {
    $old = $this->f->order([['productId' => $this->beans, 'quantity' => '1']], '2026-11-02T10:00:00Z')->order;
    DB::table('online_orders')->where('id', $old->id)->update(['status' => 'collected', 'collected_at' => '2026-11-02 10:10:00']);
    $open = $this->f->order([['productId' => $this->beans, 'quantity' => '1']], '2026-11-02T10:30:00Z')->order;

    $this->travelTo(CarbonImmutable::parse('2028-12-01 07:00:00', 'UTC'));
    expect(app(AnonymiseOnlineOrders::class)->handle())->toBe(1);

    $gone = OnlineOrder::withoutCompanyScope()->find($old->id);
    expect([$gone->customer_name, $gone->customer_email, $gone->customer_mobile, $gone->customer_mobile_key])->toBe(['Removed', '', '', null])
        ->and($gone->anonymised_at)->not->toBeNull()->and($gone->items_total)->toBe('1.10')
        ->and(OnlineOrder::withoutCompanyScope()->find($open->id)->customer_name)->toBe('Asha Patel');
});
