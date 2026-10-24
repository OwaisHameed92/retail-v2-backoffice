<?php

use App\Domain\OnlineStore\Actions\SaveStoreClosure;
use App\Domain\OnlineStore\Data\DeliveryQuote;
use App\Domain\OnlineStore\Enums\FulfilmentMethod;
use App\Domain\OnlineStore\Enums\OnlinePaymentMethod;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Queries\CollectionDays;
use App\Domain\OnlineStore\Queries\CollectionSlots;
use App\Domain\OnlineStore\Support\DeliveryArea;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

/*
 * OS-9: local delivery at checkout: postcode coverage, fee, free-over and minimum (DeliveryQuote, server side),
 * delivery slots apart from collection slots, closed days (OSA-13), and placing collection and delivery orders.
 */

const DELIVERY_SLOT = '2026-11-02T10:00:00Z';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    $this->f->deliveryOn();
    $this->beans = $this->f->product('Baked beans', ['sell_price' => '6.00']);
    $this->url = fn (string $name, array $params = []) => route($name, ['slug' => 'kirkgate', ...$params], false);
    $this->add = fn (string $quantity) => $this->post(($this->url)('store.basket.add'), ['product' => $this->beans, 'quantity' => $quantity]);
    $this->choose = fn (string $method, string $postcode = '') => $this->from(($this->url)('store.checkout'))
        ->post(($this->url)('store.checkout.method'), ['fulfilment' => $method, 'postcode' => $postcode]);
    $this->details = fn (array $overrides = []) => [
        'shopId' => $this->f->shop->id, 'fulfilment' => 'delivery', 'collectFrom' => DELIVERY_SLOT, 'customerName' => 'Asha Patel',
        'customerMobile' => '07700 900123', 'customerEmail' => 'asha@example.test', 'paymentMethod' => 'payOnDelivery',
        'deliveryLine1' => '12 High Street', 'deliveryLine2' => 'Flat 2', 'deliveryTown' => 'Birmingham', 'deliveryPostcode' => 'b24 9ab',
        'deliveryNotes' => 'Ring twice', ...$overrides,
    ];
    $this->place = fn (array $overrides = []) => $this->from(($this->url)('store.checkout'))->post(($this->url)('store.checkout.place'), ($this->details)($overrides));
});

test('postcode districts, sectors and full postcodes match exactly', function (string $postcode, bool $covered) {
    expect(DeliveryArea::covers(['B23', 'B24 9', 'LS1 6AB'], $postcode))->toBe($covered);
})->with([
    'district' => ['B23 1AA', true],
    'district, no space, lower case' => ['b231aa', true],
    'sector' => ['B24 9ZZ', true],
    'other sector of the district' => ['B24 8AA', false],
    'a longer district is not the shorter one' => ['B2 3AA', false],
    'district prefix is not a match' => ['B233 1AA', false],
    'full postcode' => ['LS1 6AB', true],
    'neighbour of the full postcode' => ['LS1 6AD', false],
    'not a postcode' => ['Birmingham', false],
]);

test('the quote: fee, free over the threshold, the minimum and clear reasons', function () {
    $shop = $this->f->shop;

    expect(DeliveryQuote::for($shop, 'B23 1AA', '12.00')->toArray())->toBe([
        'offered' => true, 'postcode' => 'B23 1AA', 'covered' => true, 'fee' => '3.50', 'freeOver' => '40.00', 'minimum' => '10.00', 'shortBy' => null, 'problem' => null,
    ])
        ->and(DeliveryQuote::for($shop, 'B23 1AA', '40.00')->fee)->toBe('0.00')
        ->and(DeliveryQuote::for($shop, 'B23 1AA', '7.50')->problem)->toBe('The minimum order for delivery is £10.00. Add £2.50 more to have it delivered.')
        ->and(DeliveryQuote::for($shop, 'M1 1AA', '20.00')->problem)->toBe('Sorry, we do not deliver to M1 1AA yet. Choose click & collect instead.')
        ->and(DeliveryQuote::for($shop, 'nope', '20.00')->problem)->toBe('Enter a full postcode, like LS1 6AB.')
        ->and(DeliveryQuote::for($shop, null, '20.00')->problem)->toBe('Enter your postcode to see if we deliver to you.');
});

test('delivery slots use the delivery hours, length, capacity and lead time, apart from collection slots', function () {
    $slots = app(CollectionSlots::class);
    $delivery = $slots->handle($this->f->shop, '2026-11-02', null, FulfilmentMethod::Delivery);
    $collection = $slots->handle($this->f->shop, '2026-11-02');

    expect(array_map(fn ($s) => $s->label, $delivery))->toBe(['10:00–11:00', '11:00–12:00', '12:00–13:00', '13:00–14:00', '14:00–15:00', '15:00–16:00', '16:00–17:00', '17:00–18:00'])
        ->and($delivery[0]->capacity)->toBe(2)->and($collection[0]->label)->toBe('08:00–08:30');

    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:30:00', 'UTC'));
    expect($slots->handle($this->f->shop, '2026-11-02', null, FulfilmentMethod::Delivery)[0]->label)->toBe('11:00–12:00');
});

test('checkout: delivery shows only for a covered postcode, with the fee in the totals; collect stays free', function () {
    ($this->add)('2');

    $this->get(($this->url)('store.checkout'))->assertInertia(fn (AssertableInertia $p) => $p
        ->where('method', 'collection')->where('delivery.offered', true)->where('delivery.problem', 'Enter your postcode to see if we deliver to you.')
        ->where('totals', ['items' => '12.00', 'delivery' => '0.00', 'grand' => '12.00'])
        ->where('payments', [['value' => 'payAtShop', 'label' => 'Pay at the shop', 'hint' => 'Card or cash when you collect. Nothing to pay online.', 'available' => true]]));

    ($this->choose)('delivery', 'M1 1AA')->assertRedirect(($this->url)('store.checkout'));
    $this->get(($this->url)('store.checkout'))->assertInertia(fn (AssertableInertia $p) => $p
        ->where('method', 'delivery')->where('delivery.covered', false)->where('delivery.problem', 'Sorry, we do not deliver to M1 1AA yet. Choose click & collect instead.')
        ->where('totals.grand', '12.00'));

    ($this->choose)('delivery', 'b24 9ab')->assertSessionHasNoErrors();
    $this->get(($this->url)('store.checkout'))->assertInertia(fn (AssertableInertia $p) => $p
        ->where('delivery.postcode', 'B24 9AB')->where('delivery.covered', true)->where('delivery.problem', null)
        ->where('totals', ['items' => '12.00', 'delivery' => '3.50', 'grand' => '15.50'])
        ->where('slots.0.label', '10:00–11:00')->where('payments.0.value', 'payOnDelivery'));

    ($this->choose)('delivery', 'not a code')->assertSessionHasErrors(['deliveryPostcode' => 'Enter a full postcode, like LS1 6AB.']);
});

test('placing a delivery order stores the address, fee and totals, re-priced on the server', function () {
    ($this->add)('2');

    ($this->place)(['deliveryFee' => '0.01', 'grand_total' => '1.00'])->assertRedirect();

    $order = OnlineOrder::withoutCompanyScope()->sole();
    expect($order->fulfilment)->toBe(FulfilmentMethod::Delivery)
        ->and($order->payment_method)->toBe(OnlinePaymentMethod::PayOnDelivery)
        ->and($order->deliveryAddress())->toBe('12 High Street, Flat 2, Birmingham, B24 9AB')
        ->and($order->delivery_notes)->toBe('Ring twice')
        ->and([$order->items_total, $order->delivery_fee, $order->grandTotal()])->toBe(['12.00', '3.50', '15.50'])
        ->and($order->collect_from->toIso8601ZuluString())->toBe(DELIVERY_SLOT)
        ->and($order->collect_to->toIso8601ZuluString())->toBe('2026-11-02T11:00:00Z')
        ->and(app(CurrentCompany::class)->has())->toBeFalse();
});

test('a delivery is refused outside the area, under the minimum, at a collection time or with a way to pay not offered', function () {
    config(['online_store.checkout_per_minute' => 100]);
    ($this->add)('1');
    ($this->place)()->assertSessionHasErrors(['lines' => 'The minimum order for delivery is £10.00. Add £4.00 more to have it delivered.']);
    ($this->add)('1');
    ($this->place)(['deliveryPostcode' => 'M1 1AA'])->assertSessionHasErrors(['deliveryPostcode' => 'Sorry, we do not deliver to M1 1AA yet. Choose click & collect instead.']);
    ($this->place)(['deliveryPostcode' => ''])->assertSessionHasErrors('deliveryPostcode');
    ($this->place)(['deliveryLine1' => '', 'deliveryTown' => ''])->assertSessionHasErrors(['deliveryLine1', 'deliveryTown']);
    ($this->place)(['collectFrom' => '2026-11-02T08:00:00Z'])->assertSessionHasErrors(['collectFrom' => 'Choose one of the delivery times shown.']);
    ($this->place)(['paymentMethod' => 'payAtShop'])->assertSessionHasErrors(['paymentMethod' => 'Choose one of the ways to pay shown.']);
    ($this->place)(['paymentMethod' => 'cardOnline'])->assertSessionHasErrors('paymentMethod');
    expect(DB::table('online_orders')->count())->toBe(0);
});

test('free delivery over the threshold; a full delivery slot does not touch the collection slot of the same time', function () {
    ($this->add)('7');
    ($this->place)()->assertRedirect();
    expect(OnlineOrder::withoutCompanyScope()->sole()->delivery_fee)->toBe('0.00');

    ($this->add)('7');
    ($this->place)(['customerEmail' => 'b@example.test'])->assertSessionHasNoErrors();
    ($this->add)('7');
    ($this->place)(['customerEmail' => 'c@example.test'])->assertSessionHasErrors(['collectFrom' => 'That delivery time is now full. Choose another one.']);

    $collect = app(CollectionSlots::class)->find($this->f->shop, CarbonImmutable::parse(DELIVERY_SLOT));
    expect($collect?->taken)->toBe(0);
});

test('a shop that does not deliver keeps S1 exactly: no delivery choice, collect and pay at the shop', function () {
    $this->f->deliveryOn(['delivery_enabled' => false]);
    ($this->add)('1');

    $this->get(($this->url)('store.checkout'))->assertInertia(fn (AssertableInertia $p) => $p->where('method', 'collection')->where('delivery', null));
    ($this->choose)('delivery', 'B23 1AA');
    $this->get(($this->url)('store.checkout'))->assertInertia(fn (AssertableInertia $p) => $p->where('method', 'collection'));
    ($this->place)(['fulfilment' => 'delivery'])->assertSessionHasErrors();

    $this->from(($this->url)('store.checkout'))->post(($this->url)('store.checkout.place'), [
        'shopId' => $this->f->shop->id, 'collectFrom' => '2026-11-02T10:00:00Z', 'customerName' => 'Asha Patel', 'customerMobile' => '07700 900123',
        'customerEmail' => 'asha@example.test',
    ])->assertRedirect();
    $order = OnlineOrder::withoutCompanyScope()->sole();
    expect($order->fulfilment)->toBe(FulfilmentMethod::Collection)->and($order->payment_method)->toBe(OnlinePaymentMethod::PayAtShop)
        ->and($order->grandTotal())->toBe('6.00');
});

test('closed days and special hours (OSA-13) apply to collection and delivery and are shown with the reason', function () {
    $save = app(SaveStoreClosure::class);
    $save->handle($this->f->company, $this->f->leeds->id, ['date' => '2026-11-03', 'all_day' => true, 'reason' => 'Staff training']);
    $save->handle($this->f->company, $this->f->leeds->id, ['date' => '2026-11-04', 'all_day' => false, 'opens_at' => '12:00', 'closes_at' => '14:00']);
    $slots = app(CollectionSlots::class);
    $days = app(CollectionDays::class)->days($this->f->shop);

    expect($slots->handle($this->f->shop, '2026-11-03'))->toBe([])
        ->and($slots->handle($this->f->shop, '2026-11-03', null, FulfilmentMethod::Delivery))->toBe([])
        ->and(array_map(fn ($s) => $s->label, $slots->handle($this->f->shop, '2026-11-04')))->toBe(['12:00–12:30', '12:30–13:00', '13:00–13:30', '13:30–14:00'])
        ->and(array_map(fn ($s) => $s->label, $slots->handle($this->f->shop, '2026-11-04', null, FulfilmentMethod::Delivery)))->toBe(['12:00–13:00', '13:00–14:00'])
        ->and($days[1])->toMatchArray(['open' => false, 'closedReason' => 'Staff training'])
        ->and($days[2]['open'])->toBeTrue();

    expect(fn () => $save->handle($this->f->company, $this->f->leeds->id, ['date' => '2026-11-01', 'all_day' => true]))->toThrow(ValidationException::class);
});
