<?php

use App\Domain\OnlineStore\Actions\PlaceCollectOrder;
use App\Domain\OnlineStore\Actions\SaveDeliverySettings;
use App\Domain\OnlineStore\Actions\SaveStoreShop;
use App\Domain\OnlineStore\Actions\SetStoreLive;
use App\Domain\OnlineStore\Enums\FulfilmentMethod;
use App\Domain\OnlineStore\Enums\StoreTemplate;
use App\Domain\OnlineStore\Mail\NewOnlineOrderMail;
use App\Domain\OnlineStore\Mail\OnlineOrderReceivedMail;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Queries\StorefrontShops;
use App\Domain\OnlineStore\Support\ShopMethods;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Http\Support\Store\StoreWording;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

/*
 * OS-12: a shop takes part in the store when collection OR delivery is on. The owner's live case: one shop with
 * collection off and delivery on (B23, "B24 9", B72; £3.00, free over £30.00, minimum £10.00; every day; 60-minute
 * slots; 120 minutes' lead). The store lives, offers delivery only, and never says "click & collect". Collect-only
 * stays as it was; both methods offer both.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));   // Monday 07:00 in London
    $this->f = OnlineStoreFixtures::make();
    $this->beans = $this->f->product('Baked beans', ['sell_price' => '6.00']);
    $this->f->product('Milk', ['department_id' => OnlineStoreFixtures::department($this->f->company, 'Dairy', 2)]);
    $this->f->product('Bread', ['department_id' => OnlineStoreFixtures::department($this->f->company, 'Bakery', 3)]);
    $this->url = fn (string $name, array $params = []) => route($name, ['slug' => 'kirkgate', ...$params], false);
    $this->sections = fn (array $keys) => OnlineStore::withoutCompanyScope()->whereKey($this->f->store->id)
        ->update(['sections' => json_encode(array_map(fn ($k) => ['key' => $k, 'enabled' => true], $keys))]);
    // The owner's settings.
    $this->delivery = fn () => $this->f->shop = app(SaveDeliverySettings::class)->handle($this->f->company, $this->f->leeds->id, OnlineStoreFixtures::deliveryInput([
        'delivery_areas' => 'B23, B24 9, B72', 'delivery_fee' => '3.00', 'delivery_free_over' => '30.00', 'delivery_minimum' => '10.00',
        'delivery_hours' => array_fill_keys(OnlineStoreShop::WEEKDAYS, ['open' => '09:00', 'close' => '21:00']),
        'delivery_slot_minutes' => 60, 'delivery_lead_minutes' => 120,
    ]));
    $this->collectionOff = fn () => $this->f->shop = app(SaveStoreShop::class)->handle($this->f->company, $this->f->leeds->id, OnlineStoreFixtures::shopInput(['enabled' => false]));
    $this->home = fn () => $this->get(($this->url)('store.home'))->assertOk()->viewData('page')['props'];
    $this->add = fn (string $quantity = '2') => $this->post(($this->url)('store.basket.add'), ['product' => $this->beans, 'quantity' => $quantity]);
    $this->checkout = fn () => $this->get(($this->url)('store.checkout'))->assertOk()->viewData('page')['props'];
    $this->deliveryOrder = fn (array $overrides = []) => $this->from(($this->url)('store.checkout'))->post(($this->url)('store.checkout.place'), [
        'shopId' => $this->f->shop->id, 'fulfilment' => 'delivery', 'collectFrom' => '2026-11-02T09:00:00Z', 'customerName' => 'Asha Patel',
        'customerMobile' => '07700 900123', 'customerEmail' => 'asha@example.test', 'paymentMethod' => 'payOnDelivery',
        'deliveryLine1' => '12 High Street', 'deliveryTown' => 'Birmingham', 'deliveryPostcode' => 'B72 1AA', ...$overrides,
    ]);
    ($this->sections)(['hero', 'departments', 'howItWorks', 'shops', 'about']);
});

/** Every string in the props, lower case, joined: to prove a word is never shown. */
function propsText(array $props): string
{
    $words = [];
    array_walk_recursive($props, function ($value) use (&$words) {
        if (is_string($value)) {
            $words[] = strtolower($value);
        }
    });

    return implode(' | ', $words);
}

test('the owner\'s delivery-only shop takes part: the store lives, with its shop, aisles and next delivery', function () {
    ($this->delivery)();
    ($this->collectionOff)();

    $props = ($this->home)();

    expect($props['store']['shopCount'])->toBe(1)
        ->and($props['store']['takingOrders'])->toBeTrue()
        ->and($props['store']['methods'])->toBe(['collection' => false, 'delivery' => true, 'mode' => 'delivery'])
        ->and($props['shops'])->toHaveCount(1)
        ->and($props['shops'][0]['collects'])->toBeFalse()
        ->and($props['shops'][0]['delivery']['areas'])->toBe(['B23', 'B24 9', 'B72'])
        ->and($props['shops'][0]['delivery']['fee'])->toBe('3.00')
        ->and($props['shops'][0]['delivery']['freeOver'])->toBe('30.00')
        ->and($props['shops'][0]['delivery']['minimum'])->toBe('10.00')
        ->and($props['shops'][0]['delivery']['hours'][0])->toBe(['day' => 'Monday', 'hours' => ['open' => '09:00', 'close' => '21:00']])
        ->and(collect($props['departmentTiles'])->pluck('name')->all())->toBe(['Grocery', 'Dairy', 'Bakery'])
        // 07:00 + 120 minutes' lead: the first delivery slot is 09:00–10:00.
        ->and($props['nextSlot'])->toBe(['method' => 'delivery', 'when' => 'Today 09:00–10:00'])
        ->and($props['nextCollection'])->toBeNull()
        ->and($props['store']['shop']['hours'][0]['hours'])->toBe(['open' => '09:00', 'close' => '21:00'])
        ->and($props['store']['shop']['openNow'])->toBe(['open' => false, 'label' => 'Delivers today from 09:00'])
        ->and(app(StorefrontShops::class)->handle($this->f->store->refresh())->pluck('id')->all())->toBe([$this->f->shop->id]);
});

test('delivery-only words: no "click & collect" anywhere, delivery areas and fees in "Where we deliver"', function () {
    ($this->delivery)();
    ($this->collectionOff)();

    $props = ($this->home)();
    $wording = $props['store']['wording'];

    expect($wording['mode'])->toBe('delivery')
        ->and($wording['chip'])->toBe('Local delivery')
        ->and($wording['strip'])->toBe('Order online, delivered to your door from Leeds')
        ->and($wording['heroText'])->toBe('Order online and we deliver it to your door from Leeds. Choose a delivery time at checkout.')
        ->and($wording['how']['eyebrow'])->toBe('Local delivery')
        ->and($wording['how']['title'])->toBe('Shop online, delivered to your door')
        ->and($wording['how']['steps'][1]['text'])->toBe('Choose a delivery slot. We pack your order and bring it to your door.')
        ->and($wording['how']['steps'][2]['text'])->toBe('Pay the driver by card or cash when it arrives.')
        ->and($wording['where'])->toBe(['title' => 'Where we deliver', 'sub' => 'Delivery areas, fees and hours'])
        ->and($wording['footer'])->toBe('Your local shop, online. Order from the shelves you know, at the shelf price, delivered to your door.')
        ->and($wording['hoursTitle'])->toBe('Delivery hours')
        ->and($wording['shopLabel'])->toBe('Delivery from')
        ->and($wording['basketEmpty'])->toBe('Add a few things and pick a time to have them delivered.')
        ->and($wording['payNote'])->toBe('Pay the driver by card or cash when it arrives.')
        ->and(propsText($props))->not->toContain('collect');

    ($this->add)();
    $this->get(($this->url)('store.product', ['product' => $this->beans]))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('nextSlot', ['method' => 'delivery', 'when' => 'Today 09:00–10:00']));
    expect(propsText($this->get(($this->url)('store.basket'))->viewData('page')['props']))->not->toContain('click & collect')
        ->and(propsText(($this->checkout)()))->not->toContain('click & collect');

    $this->get(($this->url)('store.home'))->assertSee('Order online, local delivery', false)->assertDontSee('click &amp; collect', false);
    $this->get(($this->url)('store.terms'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('sections', fn ($sections) => ! collect($sections)->pluck('heading')->contains('Click & collect')
            && collect($sections)->pluck('heading')->contains('Local delivery')));
});

test('a delivery-only shop\'s checkout offers delivery only, with slots from its delivery hours', function () {
    ($this->delivery)();
    ($this->collectionOff)();
    ($this->add)();
    // Even a collection choice left in the session cannot bring the collect option back.
    $this->from(($this->url)('store.checkout'))->post(($this->url)('store.checkout.method'), ['fulfilment' => 'collection', 'postcode' => '']);

    $props = ($this->checkout)();

    expect($props['methods'])->toBe(['delivery'])
        ->and($props['method'])->toBe('delivery')
        ->and($props['delivery']['offered'])->toBeTrue()
        ->and($props['day'])->toBe('2026-11-02')
        ->and(array_column($props['slots'], 'label'))->toBe(['09:00–10:00', '10:00–11:00', '11:00–12:00', '12:00–13:00', '13:00–14:00', '14:00–15:00',
            '15:00–16:00', '16:00–17:00', '17:00–18:00', '18:00–19:00', '19:00–20:00', '20:00–21:00'])
        ->and(array_column($props['payments'], 'value'))->toBe(['payOnDelivery']);

    $this->from(($this->url)('store.checkout'))->post(($this->url)('store.checkout.method'), ['fulfilment' => 'delivery', 'postcode' => 'M1 1AA']);
    expect(($this->checkout)()['delivery']['problem'])->toBe('Sorry, we do not deliver to M1 1AA yet.');
});

test('a delivery-only shop takes a delivery order and refuses a click & collect one', function () {
    Mail::fake();
    ($this->delivery)();
    ($this->collectionOff)();
    ($this->add)();

    ($this->deliveryOrder)()->assertSessionHasNoErrors()->assertRedirect();

    $order = OnlineOrder::withoutCompanyScope()->sole();
    expect($order->fulfilment)->toBe(FulfilmentMethod::Delivery)
        ->and($order->delivery_postcode)->toBe('B72 1AA')
        ->and($order->collect_from->toIso8601ZuluString())->toBe('2026-11-02T09:00:00Z')
        ->and($order->items_total)->toBe('12.00')
        ->and($order->delivery_fee)->toBe('3.00');

    Mail::assertQueued(NewOnlineOrderMail::class, function (NewOnlineOrderMail $mail) {
        $html = $mail->render();

        return str_contains($html, 'placed a delivery order') && str_contains($html, 'Deliver to')
            && ! str_contains(strtolower($html), 'click &amp; collect') && ! str_contains($html, 'Collect from');
    });
    Mail::assertQueued(OnlineOrderReceivedMail::class, fn (OnlineOrderReceivedMail $mail) => ! str_contains($mail->render(), 'Collect from'));

    expect(fn () => app(PlaceCollectOrder::class)->handle($this->f->store->refresh(), $this->f->input([['productId' => $this->beans, 'quantity' => '2']],
        '2026-11-02T09:00:00Z', ['fulfilment' => 'collection'])))
        ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey('fulfilment'));
});

test('a shop with collection and delivery both off takes no part; go live needs a shop that collects or delivers', function () {
    ($this->collectionOff)();

    expect(app(StorefrontShops::class)->handle($this->f->store->refresh()))->toHaveCount(0);
    $this->get(($this->url)('store.home'))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('store.shopCount', 0)->where('store.takingOrders', false));

    // Delivery on but no way left to pay for it: still out.
    ($this->delivery)();
    $this->f->store->forceFill(['delivery_payments' => ['cardOnline']])->save();
    expect(app(StorefrontShops::class)->handle($this->f->store->refresh()))->toHaveCount(0);
});

test('collect-only is unchanged: its words, its checkout and its next collection', function () {
    ($this->add)();
    $props = ($this->home)();
    $wording = $props['store']['wording'];

    expect($props['store']['methods']['mode'])->toBe('collection')
        ->and($wording['chip'])->toBe('Click & collect')
        ->and($wording['strip'])->toBe('Order online, pay when you collect at Leeds')
        ->and($wording['heroText'])->toBe('Order online and collect from Leeds in about 30 minutes. Pay at the counter when you pick it up.')
        ->and($wording['how']['title'])->toBe('Shop online, skip the queue')
        ->and($wording['how']['steps'][1]['text'])->toBe('Choose a collection slot. We need about 30 minutes to get it ready.')
        ->and($wording['where'])->toBe(['title' => 'Where to collect', 'sub' => 'Opening hours for collection'])
        ->and($wording['hoursTitle'])->toBe('Opening hours')
        ->and($wording['shopLabel'])->toBe('Collect from')
        ->and($wording['payNote'])->toBe('You pay at the shop when you collect.')
        ->and($props['nextSlot'])->toBe(['method' => 'collection', 'when' => 'Today 08:00–08:30'])
        ->and($props['nextCollection'])->toBe('Today 08:00–08:30')
        ->and($props['shops'][0]['collects'])->toBeTrue()
        ->and($props['shops'][0]['delivery'])->toBeNull()
        ->and($props['store']['shop']['openNow']['label'])->toBe('Opens today at 08:00');

    $checkout = ($this->checkout)();
    expect($checkout['methods'])->toBe(['collection'])->and($checkout['method'])->toBe('collection')->and($checkout['delivery'])->toBeNull()
        ->and($checkout['slots'][0]['label'])->toBe('08:00–08:30');
    $this->get(($this->url)('store.home'))->assertSee('Order online, click &amp; collect', false);
});

test('both methods: both offered at checkout, words for both, the earlier slot first', function () {
    ($this->delivery)();
    ($this->add)();
    $props = ($this->home)();
    $wording = $props['store']['wording'];

    expect($props['store']['methods'])->toBe(['collection' => true, 'delivery' => true, 'mode' => 'both'])
        ->and($wording['chip'])->toBe('Collect or delivery')
        ->and($wording['strip'])->toBe('Order online: collect at Leeds or have it delivered')
        ->and($wording['how']['title'])->toBe('Shop online, your way')
        ->and($wording['where'])->toBe(['title' => 'Collect or get it delivered', 'sub' => 'Opening hours for collection, and where we deliver'])
        ->and($props['nextSlot'])->toBe(['method' => 'collection', 'when' => 'Today 08:00–08:30'])
        ->and($props['shops'][0]['collects'])->toBeTrue()
        ->and($props['shops'][0]['delivery']['areas'])->toBe(['B23', 'B24 9', 'B72']);

    expect(($this->checkout)()['methods'])->toBe(['collection', 'delivery']);
    $this->from(($this->url)('store.checkout'))->post(($this->url)('store.checkout.method'), ['fulfilment' => 'delivery', 'postcode' => 'B23 1AA']);
    $checkout = ($this->checkout)();
    expect($checkout['method'])->toBe('delivery')->and($checkout['slots'][0]['label'])->toBe('09:00–10:00')
        ->and($checkout['delivery']['problem'])->toBeNull();
    $this->from(($this->url)('store.checkout'))->post(($this->url)('store.checkout.method'), ['fulfilment' => 'delivery', 'postcode' => 'M1 1AA']);
    expect(($this->checkout)()['delivery']['problem'])->toBe('Sorry, we do not deliver to M1 1AA yet. Choose click & collect instead.');
});

test('the words per mode and template', function () {
    $make = fn (string $mode, StoreTemplate $template = StoreTemplate::FreshMarket) => StoreWording::make($mode, $template, 'Leeds', 20, 'Pay by card online when you order');

    expect($make(ShopMethods::DELIVERY, StoreTemplate::Butcher)['heroText'])
        ->toBe('Choose the cut and the weight online. We prepare it and deliver it to your door, and you pay the real weight.')
        ->and($make(ShopMethods::DELIVERY, StoreTemplate::Butcher)['footer'])->not->toContain('counter')
        ->and($make(ShopMethods::DELIVERY)['payHint'])->toBe('Pay by card online when you order')
        ->and($make(ShopMethods::DELIVERY)['facts']['ready']['title'])->toBe('Delivered to you')
        ->and($make(ShopMethods::COLLECTION, StoreTemplate::Butcher)['heroText'])
        ->toBe('Choose the cut and the weight online. We prepare it for your collection time, ready in about 20 minutes, and you pay the real weight at the counter.')
        ->and($make(ShopMethods::BOTH)['heroText'])->toBe('Order online and collect from Leeds in about 20 minutes, or have it delivered to your door.')
        ->and($make(ShopMethods::BOTH)['how']['steps'][1]['text'])->toBe('Choose a collection slot (ready in about 20 minutes) or a delivery time.')
        ->and(StoreWording::make(ShopMethods::BOTH, StoreTemplate::FreshMarket, 'Leeds', 20, 'x', 2)['where']['title'])->toBe('Our shops');

    foreach ([StoreTemplate::FreshMarket, StoreTemplate::Butcher] as $template) {
        expect(strtolower(json_encode($make(ShopMethods::DELIVERY, $template))))->not->toContain('collect');
    }
});

test('another business\'s delivery-only shop never shows in this store or takes its orders', function () {
    $other = OnlineStoreFixtures::make('Other Shop', 'other-shop');
    $other->deliveryOn();
    app(SaveStoreShop::class)->handle($other->company, $other->leeds->id, OnlineStoreFixtures::shopInput(['enabled' => false]));
    ($this->delivery)();
    ($this->collectionOff)();
    ($this->add)();

    $props = ($this->home)();
    expect(collect($props['shops'])->pluck('id')->all())->toBe([$this->f->shop->id])
        ->and(app(StorefrontShops::class)->handle($this->f->store->refresh())->pluck('company_id')->unique()->all())->toBe([$this->f->company->id])
        ->and(app(StorefrontShops::class)->find($this->f->store, $other->shop->id))->toBeNull();

    ($this->deliveryOrder)(['shopId' => $other->shop->id])->assertSessionHasErrors('shopId');
    expect(OnlineOrder::withoutCompanyScope()->count())->toBe(0);
});

test('portal: a delivery-only shop counts for going live, and the tabs say so', function () {
    ($this->delivery)();
    ($this->collectionOff)();
    app(SetStoreLive::class)->handle($this->f->company, false);
    $owner = C::member($this->f->company, CompanyRole::Owner);

    $this->actingAs($owner)->putJson('/app/online-store/live', ['on' => true])->assertSessionHasNoErrors();
    expect($this->f->store->refresh()->is_live)->toBeTrue();

    $this->actingAs($owner)->get('/app/online-store')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('checklist.collectionShops', 1)->where('checklist.shopMode', 'delivery'));
    $this->actingAs($owner)->get('/app/online-store/shops')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('shops', fn ($shops) => collect($shops)->firstWhere('id', $this->f->leeds->id)['enabled'] === false
            && collect($shops)->firstWhere('id', $this->f->leeds->id)['delivers'] === true));
});
