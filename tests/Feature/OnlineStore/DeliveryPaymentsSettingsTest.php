<?php

use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\Tenancy\Enums\CompanyRole;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;
use Tests\Feature\Purchasing\PurchasingFixtures as F;

/*
 * OS-9: the portal's "Delivery & payments" tab: delivery per collection shop (SaveDeliverySettings), the ways to pay
 * (SavePaymentSettings) and the store's DNA Payments account (SaveDnaCredentials, encrypted, never sent back).
 */

beforeEach(function () {
    $this->withoutVite();
    $this->f = OnlineStoreFixtures::make();
    $this->owner = F::member($this->f->company, CompanyRole::Owner);
    $this->put = fn (string $url, array $data, $user = null) => $this->actingAs($user ?? $this->owner)->from('/app/online-store/delivery')->put($url, $data);
    $this->leedsUrl = "/app/online-store/delivery/shops/{$this->f->leeds->id}";
    $this->shopRow = fn () => OnlineStoreShop::withoutCompanyScope()->where('company_id', $this->f->company->id)->where('branch_id', $this->f->leeds->id)->sole();
});

test('the tab lists the collection shops with their delivery settings and the payment options', function () {
    $this->actingAs($this->owner)->get('/app/online-store/delivery')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('app/online-store/delivery')
        ->where('hasStore', true)->where('canEditPayments', true)
        ->has('shops', 1)->where('shops.0.name', 'Leeds')->where('shops.0.enabled', false)->where('shops.0.hoursSaved', false)
        ->where('shops.0.days.0', ['day' => 'monday', 'open' => '08:00', 'close' => '20:00'])
        ->where('payments.cardReady', false)->where('payments.collection.chosen', ['payAtShop'])->where('payments.delivery.chosen', ['payOnDelivery'])
        ->where('dna.configured', false)->where('dna.secret', null));
});

test('saving delivery: areas are cleaned, money is 2 dp, hours and slots are kept', function () {
    ($this->put)($this->leedsUrl, OnlineStoreFixtures::deliveryInput(['delivery_areas' => "b23, B24  9\nls1 6ab;B23"]))
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Leeds saved. Customers in its area can choose delivery.');

    $shop = ($this->shopRow)();
    expect($shop->delivery_enabled)->toBeTrue()
        ->and($shop->delivery_areas)->toBe(['B23', 'B24 9', 'LS1 6AB'])
        ->and($shop->delivery_fee)->toBe('3.50')->and($shop->delivery_free_over)->toBe('40.00')->and($shop->delivery_minimum)->toBe('10.00')
        ->and($shop->deliveryHoursOn('friday'))->toBe(['open' => '10:00', 'close' => '18:00'])
        ->and($shop->delivery_slot_minutes)->toBe(60)->and($shop->delivery_orders_per_slot)->toBe(2)->and($shop->delivery_lead_minutes)->toBe(60)
        ->and(DB::table('audit_logs')->where('action', 'online_store.delivery_updated')->count())->toBe(1);
});

test('delivery settings are checked (422)', function (array $override, string $field) {
    $this->actingAs($this->owner)->putJson($this->leedsUrl, OnlineStoreFixtures::deliveryInput($override))->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(($this->shopRow)()->delivery_enabled)->toBeFalse();
})->with([
    'no area while on' => [['delivery_areas' => ''], 'delivery_areas'],
    'not an area' => [['delivery_areas' => 'B23, Birmingham'], 'delivery_areas'],
    'fee with 3 dp' => [['delivery_fee' => '2.555'], 'delivery_fee'],
    'negative minimum' => [['delivery_minimum' => '-5'], 'delivery_minimum'],
    'closing before opening' => [['delivery_hours' => ['monday' => ['open' => '18:00', 'close' => '10:00']]], 'delivery_hours.monday.close'],
    'no day at all' => [['delivery_hours' => array_fill_keys(OnlineStoreShop::WEEKDAYS, null)], 'delivery_hours'],
    'slot too short' => [['delivery_slot_minutes' => 5], 'delivery_slot_minutes'],
    'no orders per slot' => [['delivery_orders_per_slot' => 0], 'delivery_orders_per_slot'],
]);

test('only collection shops can deliver; turning it off needs no area', function () {
    $this->actingAs($this->owner)->putJson("/app/online-store/delivery/shops/{$this->f->bradford->id}", OnlineStoreFixtures::deliveryInput())
        ->assertUnprocessable()->assertJsonValidationErrors(['branch_id' => 'Add this shop as a collection shop first.']);

    ($this->put)($this->leedsUrl, OnlineStoreFixtures::deliveryInput(['delivery_enabled' => false, 'delivery_areas' => '']))->assertSessionHasNoErrors();
    expect(($this->shopRow)()->delivers())->toBeFalse();
});

test('DNA credentials are stored encrypted, shown as the last 4 only, and never sent to the browser', function () {
    ($this->put)('/app/online-store/delivery/dna', ['client_id' => 'client-abcd1234', 'client_secret' => 'super-secret-9876', 'terminal_id' => 'term-0042', 'test_mode' => true])
        ->assertSessionHasNoErrors()->assertSessionHas('success', 'DNA Payments details saved in test mode: no real money is taken until you switch test mode off.');

    $raw = DB::table('online_stores')->where('company_id', $this->f->company->id)->first();
    expect($raw->dna_client_secret)->not->toBe('super-secret-9876')->not->toContain('super-secret')
        ->and($raw->dna_client_id)->not->toContain('client-abcd')->and($raw->dna_terminal_id)->not->toContain('term-0042')
        ->and(OnlineStore::withoutCompanyScope()->find($raw->id)->dna_client_secret)->toBe('super-secret-9876')
        ->and(OnlineStore::withoutCompanyScope()->find($raw->id)->toArray())->not->toHaveKeys(['dna_client_secret', 'dna_client_id', 'dna_terminal_id']);

    $page = $this->actingAs($this->owner)->get('/app/online-store/delivery')->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('dna', ['configured' => true, 'clientId' => '••••1234', 'terminalId' => '••••0042', 'secret' => '••••9876', 'testMode' => true])
        ->where('payments.cardReady', true));
    expect($page->getContent())->not->toContain('super-secret-9876')->not->toContain('client-abcd1234');
    expect(DB::table('audit_logs')->where('action', 'online_store.dna_saved')->count())->toBe(1)
        ->and(json_encode(DB::table('audit_logs')->get()))->not->toContain('super-secret');

    // A blank secret keeps the saved one.
    ($this->put)('/app/online-store/delivery/dna', ['client_id' => 'client-abcd1234', 'client_secret' => '', 'terminal_id' => 'term-0042', 'test_mode' => false])->assertSessionHasNoErrors();
    expect(OnlineStore::withoutCompanyScope()->find($raw->id)->dna_client_secret)->toBe('super-secret-9876');
});

test('card online can be offered only once DNA is set up; removing DNA takes card off again', function () {
    $this->actingAs($this->owner)->putJson('/app/online-store/delivery/payments', ['collection' => ['payAtShop', 'cardOnline'], 'delivery' => ['payOnDelivery']])
        ->assertUnprocessable()->assertJsonValidationErrors(['collection' => 'Save your DNA Payments details first to take card payments online.']);
    $this->actingAs($this->owner)->putJson('/app/online-store/delivery/payments', ['collection' => [], 'delivery' => ['payOnDelivery']])
        ->assertUnprocessable()->assertJsonValidationErrors('collection');
    $this->actingAs($this->owner)->putJson('/app/online-store/delivery/payments', ['collection' => ['payOnDelivery'], 'delivery' => ['payOnDelivery']])
        ->assertUnprocessable()->assertJsonValidationErrors('collection');

    $this->f->cardOn(['cardOnline'], ['cardOnline']);
    expect($this->f->store->refresh()->collection_payments)->toBe(['cardOnline']);

    $this->actingAs($this->owner)->delete('/app/online-store/delivery/dna')->assertRedirect();
    $store = $this->f->store->refresh();
    expect($store->dna_client_secret)->toBeNull()->and($store->collection_payments)->toBe(['payAtShop'])->and($store->delivery_payments)->toBe(['payOnDelivery']);
});

test('a one-shop manager changes only their own shop\'s delivery and never the payments', function () {
    $manager = F::member($this->f->company, CompanyRole::Manager, $this->f->leeds);

    $this->actingAs($manager)->get('/app/online-store/delivery')->assertOk()->assertInertia(fn (Assert $p) => $p->where('canEditPayments', false)->has('shops', 1));
    ($this->put)($this->leedsUrl, OnlineStoreFixtures::deliveryInput(), $manager)->assertSessionHasNoErrors();
    ($this->put)("/app/online-store/delivery/shops/{$this->f->bradford->id}", OnlineStoreFixtures::deliveryInput(), $manager)->assertForbidden();
    ($this->put)('/app/online-store/delivery/payments', ['collection' => ['payAtShop'], 'delivery' => ['payOnDelivery']], $manager)->assertForbidden();
    ($this->put)('/app/online-store/delivery/dna', ['client_id' => 'x', 'client_secret' => 'y', 'terminal_id' => 'z', 'test_mode' => true], $manager)->assertForbidden();
});

test('guests are sent to sign in, and a cashier cannot open the tab', function () {
    $this->get('/app/online-store/delivery')->assertRedirect('/login');
    $this->put($this->leedsUrl, OnlineStoreFixtures::deliveryInput())->assertRedirect('/login');
    $cashier = F::member($this->f->company, CompanyRole::Staff);
    $this->actingAs($cashier)->get('/app/online-store/delivery')->assertForbidden();
});

test('company A can never read or change company B\'s delivery or payments', function () {
    $b = OnlineStoreFixtures::make('Briggate Butchers', 'briggate');
    $b->cardOn();

    $this->actingAs($this->owner)->get('/app/online-store/delivery')->assertInertia(fn (Assert $p) => $p->has('shops', 1)->where('shops.0.name', 'Leeds')->where('dna.configured', false));
    $this->actingAs($this->owner)->putJson("/app/online-store/delivery/shops/{$b->leeds->id}", OnlineStoreFixtures::deliveryInput())->assertUnprocessable();
    ($this->put)('/app/online-store/delivery/dna', ['client_id' => 'mine', 'client_secret' => 'mine-secret', 'terminal_id' => 'mine-t', 'test_mode' => true])->assertSessionHasNoErrors();

    expect(OnlineStoreShop::withoutCompanyScope()->where('company_id', $b->company->id)->where('branch_id', $b->leeds->id)->sole()->delivery_enabled)->toBeFalse()
        ->and(OnlineStore::withoutCompanyScope()->find($b->store->id)->dna_client_secret)->toBe(OnlineStoreFixtures::DNA_SECRET);
});
