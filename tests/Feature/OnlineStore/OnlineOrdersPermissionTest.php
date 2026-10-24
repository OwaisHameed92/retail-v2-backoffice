<?php

use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;
use Tests\Feature\OnlineStore\StoreTestRoutes;

/*
| Audit OSA-32: `orders.online` (work the orders) is split from `store.manage` (build the store). Owner and manager
| have both; staff work orders only.
*/

beforeEach(function () {
    Mail::fake();
    StoreTestRoutes::ensure();
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    $this->beans = $this->f->product('Beans');
    $this->order = $this->f->order([['productId' => $this->beans, 'quantity' => '3']], '2026-11-02T10:00:00Z')->order;
    $this->staff = C::member($this->f->company, CompanyRole::Staff);
    $this->statusOf = fn (OnlineOrder $order) => OnlineOrder::withoutCompanyScope()->find($order->id)?->status;
});

test('staff open the board, an order and its pick list, and move the order on', function () {
    $this->actingAs($this->staff)->get('/app/online-orders')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('app/online-orders/index')->where('board.columns.new.count', 1));
    $this->actingAs($this->staff)->get("/app/online-orders/{$this->order->id}")->assertOk();
    $this->actingAs($this->staff)->get("/app/online-orders/{$this->order->id}/pick-list")->assertOk();

    $this->actingAs($this->staff)->from('/app/online-orders')->post("/app/online-orders/{$this->order->id}/status", ['status' => 'picking'])
        ->assertRedirect('/app/online-orders');
    expect(($this->statusOf)($this->order))->toBe(OnlineOrderStatus::Picking);

    $this->actingAs($this->staff)->from('/app/online-orders')->post("/app/online-orders/{$this->order->id}/undo")->assertRedirect('/app/online-orders');
    expect(($this->statusOf)($this->order))->toBe(OnlineOrderStatus::New);

    // The board's "Taking orders" switch is order work too (a one-shop user still may not use it).
    $this->actingAs($this->staff)->from('/app/online-orders')->put('/app/online-orders/taking', ['taking' => false])->assertRedirect('/app/online-orders');
    expect(OnlineStore::withoutCompanyScope()->find($this->f->store->id)?->taking_orders)->toBeFalse();
    $leedsStaff = C::member($this->f->company, CompanyRole::Staff, $this->f->leeds->id);
    $this->actingAs($leedsStaff)->put('/app/online-orders/taking', ['taking' => true])->assertForbidden();
});

test('staff cannot open or change the store builder, shops, products, delivery or emails', function () {
    foreach (['/app/online-store', '/app/online-store/shops', '/app/online-store/products', '/app/online-store/delivery', '/app/online-store/emails'] as $path) {
        $this->actingAs($this->staff)->get($path)->assertForbidden();
    }

    $before = OnlineStore::withoutCompanyScope()->find($this->f->store->id);
    $this->actingAs($this->staff)->post('/app/online-store', ['slug' => 'taken-over'])->assertForbidden();
    $this->actingAs($this->staff)->put('/app/online-store/live', ['on' => false])->assertForbidden();
    $this->actingAs($this->staff)->put('/app/online-store/taking-orders', ['on' => false])->assertForbidden();
    $this->actingAs($this->staff)->put("/app/online-store/shops/{$this->f->leeds->id}", OnlineStoreFixtures::shopInput())->assertForbidden();

    $after = OnlineStore::withoutCompanyScope()->find($this->f->store->id);
    expect($after?->slug)->toBe($before?->slug)->and($after?->is_live)->toBeTrue()->and($after?->taking_orders)->toBeTrue();
});

test('the menu: staff see Online orders (with the new count), not the store builder; owners and managers see both', function () {
    $this->actingAs($this->staff)->get('/app')->assertInertia(fn (Assert $page) => $page->where('onlineOrdersNew', 1)
        ->where('abilities', fn ($a) => collect($a)->contains('orders.online') && ! collect($a)->contains('store.manage')));

    foreach ([CompanyRole::Owner, CompanyRole::Manager] as $role) {
        $this->actingAs(C::member($this->f->company, $role))->get('/app')->assertInertia(fn (Assert $page) => $page
            ->where('abilities', fn ($a) => collect($a)->contains('orders.online') && collect($a)->contains('store.manage')));
    }

    $this->actingAs(C::member($this->f->company, CompanyRole::Accountant))->get('/app')->assertInertia(fn (Assert $page) => $page
        ->where('onlineOrdersNew', null)->where('abilities', fn ($a) => ! collect($a)->contains('orders.online')));

    config(['online_store.enabled' => false]);
    $this->actingAs($this->staff)->get('/app')->assertInertia(fn (Assert $page) => $page->where('onlineOrdersNew', null)
        ->where('abilities', fn ($a) => ! collect($a)->contains('orders.online')));
    $this->actingAs($this->staff)->get('/app/online-orders')->assertNotFound();
});

test('one-shop staff work only their own shop\'s orders; another business\'s staff see none', function () {
    $other = OnlineStoreFixtures::make('Briggate Butchers', 'briggate');
    $theirStaff = C::member($other->company, CompanyRole::Staff);
    $bradfordStaff = C::member($this->f->company, CompanyRole::Staff, $this->f->bradford->id);
    $this->actingAs($bradfordStaff)->get("/app/online-orders/{$this->order->id}")->assertNotFound();
    $this->actingAs($bradfordStaff)->post("/app/online-orders/{$this->order->id}/status", ['status' => 'picking'])->assertNotFound();

    $this->actingAs($theirStaff)->get("/app/online-orders/{$this->order->id}")->assertNotFound();
    $this->actingAs($theirStaff)->post("/app/online-orders/{$this->order->id}/status", ['status' => 'picking'])->assertNotFound();
    expect(($this->statusOf)($this->order))->toBe(OnlineOrderStatus::New);
});
