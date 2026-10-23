<?php

use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;
use Tests\Feature\OnlineStore\StoreTestRoutes;

/* OS-4: the portal's online orders board, an order's page and pick list, moving orders on, permissions and isolation. */

beforeEach(function () {
    Mail::fake();
    StoreTestRoutes::ensure();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    $this->beans = $this->f->product('Beans');
    $this->order = $this->f->order([['productId' => $this->beans, 'quantity' => '3']], '2026-11-02T10:00:00Z')->order;
    $this->owner = C::member($this->f->company, CompanyRole::Owner);
    $this->move = fn (OnlineOrder $order, string $status, array $extra = [], $user = null) => $this->actingAs($user ?? $this->owner)
        ->from('/app/online-orders')->post("/app/online-orders/{$order->id}/status", ['status' => $status, ...$extra]);
    $this->statusOf = fn (OnlineOrder $order) => OnlineOrder::withoutCompanyScope()->find($order->id)?->status;
});

test('the board shows today\'s orders in columns with the day\'s figures; new orders for any day stay in New (OS-6 fix)', function () {
    $tomorrow = $this->f->order([['productId' => $this->beans, 'quantity' => '1']], '2026-11-03T10:00:00Z')->order;

    $props = C::props($this->actingAs($this->owner)->get('/app/online-orders')
        ->assertInertia(fn (Assert $page) => $page->component('app/online-orders/index')
            ->where('store', ['isLive' => true, 'takingOrders' => true])
            ->where('filters.day', '2026-11-02')->where('filters.isToday', true)->where('filters.shopLocked', false)
            ->where('board.columns.new.count', 2)
            ->where('board.columns.new.cards.0.reference', 'W-0001')
            ->where('board.columns.new.cards.0.otherDay', false)
            ->where('board.columns.new.cards.1.id', $tomorrow->id)
            ->where('board.columns.new.cards.1.otherDay', true)
            ->where('board.columns.new.cards.0.customer', 'Asha P.')
            ->where('board.columns.new.cards.0.itemCount', 3)
            ->where('board.columns.new.cards.0.total', '3.00')
            ->where('board.columns.new.cards.0.collectFrom', '2026-11-02T10:00:00Z')
            ->where('board.columns.new.cards.0.fresh', true)
            ->where('board.stats', ['orders' => 1, 'value' => '3.00', 'average' => '3.00', 'waiting' => 2])
            ->where('board.later', [['day' => '2026-11-03', 'count' => 1]])));
    expect($props['board']['columns']['picking']['count'])->toBe(0);

    $this->actingAs($this->owner)->get('/app/online-orders?day=2026-11-03&shop=all')
        ->assertInertia(fn (Assert $page) => $page->where('filters.isToday', false)
            ->where('board.columns.new.cards.1.id', $tomorrow->id)->where('board.columns.new.cards.1.otherDay', false)
            ->where('board.columns.new.cards.0.otherDay', true)->where('board.columns.new.count', 2)->where('board.later', []));
});

test('owners and managers open the board; accountants and staff get 403; guests sign in; switched off is 404', function () {
    $this->actingAs(C::member($this->f->company, CompanyRole::Manager))->get('/app/online-orders')->assertOk();

    foreach ([CompanyRole::Accountant, CompanyRole::Staff] as $role) {
        $user = C::member($this->f->company, $role);
        $this->actingAs($user)->get('/app/online-orders')->assertForbidden();
        $this->actingAs($user)->get("/app/online-orders/{$this->order->id}")->assertForbidden();
        ($this->move)($this->order, 'picking', [], $user)->assertForbidden();
    }

    $this->app['auth']->forgetGuards();
    $this->get('/app/online-orders')->assertRedirect(route('login'));
    $this->post("/app/online-orders/{$this->order->id}/status", ['status' => 'picking'])->assertRedirect(route('login'));

    config(['online_store.enabled' => false]);
    $this->actingAs($this->owner)->get('/app/online-orders')->assertNotFound();
    $this->actingAs($this->owner)->get("/app/online-orders/{$this->order->id}")->assertNotFound();
    ($this->move)($this->order, 'picking')->assertNotFound();
    expect(($this->statusOf)($this->order))->toBe(OnlineOrderStatus::New);
});

test('the order page and pick list show the lines, customer, slot and history', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:15:00', 'UTC'));
    ($this->move)($this->order, 'picking')->assertRedirect('/app/online-orders');

    $this->actingAs($this->owner)->get("/app/online-orders/{$this->order->id}")
        ->assertInertia(fn (Assert $page) => $page->component('app/online-orders/show')
            ->where('business', 'Kirkgate Convenience')
            ->where('order.customerName', 'Asha Patel')->where('order.customerMobile', '07700 900123')->where('order.customerEmail', 'asha@example.test')
            ->where('order.shop', 'Leeds')->where('order.status', 'picking')->where('order.next', ['ready', 'cancelled'])
            ->where('order.lines.0', ['id' => $this->order->lines()->first()?->id, 'name' => 'Beans', 'quantity' => '3', 'isWeighed' => false, 'unitPrice' => '1.00', 'lineTotal' => '3.00'])
            ->where('order.history', [
                ['status' => 'new', 'at' => '2026-11-02T07:00:00Z', 'note' => null],
                ['status' => 'picking', 'at' => '2026-11-02T08:15:00Z', 'note' => null],
            ]));

    $this->actingAs($this->owner)->get("/app/online-orders/{$this->order->id}/pick-list")
        ->assertInertia(fn (Assert $page) => $page->component('app/online-orders/pick-list')->where('order.reference', 'W-0001'));
});

test('orders move new → picking → ready → collected from the board, then show as done today', function () {
    ($this->move)($this->order, 'picking')->assertRedirect('/app/online-orders')->assertSessionHas('success', 'W-0001 is being picked.');
    expect(($this->statusOf)($this->order))->toBe(OnlineOrderStatus::Picking);

    ($this->move)($this->order, 'ready')->assertSessionHas('success', 'W-0001 is ready. Asha P. is emailed to come and collect it.');
    expect(($this->statusOf)($this->order))->toBe(OnlineOrderStatus::Ready);

    ($this->move)($this->order, 'collected')->assertSessionHasNoErrors();
    expect(($this->statusOf)($this->order))->toBe(OnlineOrderStatus::Collected);

    $this->actingAs($this->owner)->get('/app/online-orders')
        ->assertInertia(fn (Assert $page) => $page->where('board.columns.new.count', 0)->where('board.columns.done.count', 1)
            ->where('board.columns.done.cards.0.status', 'collected')->where('board.columns.done.cards.0.doneAt', '2026-11-02T07:00:00Z')
            ->where('board.stats.orders', 1)->where('board.stats.waiting', 0));
    $this->actingAs($this->owner)->get('/app/online-orders?day=2026-11-01')->assertInertia(fn (Assert $page) => $page->where('board.columns.done.count', 0));
});

test('moves the order cannot make are refused: :dataset', function (array $path, string $to) {
    foreach ($path as $step) {
        ($this->move)($this->order, $step, ['reason' => 'Shop closed'])->assertSessionHasNoErrors();
    }

    ($this->move)($this->order, $to, ['reason' => 'Shop closed'])->assertRedirect('/app/online-orders')->assertSessionHasErrors('status');
    expect(($this->statusOf)($this->order)?->value)->toBe($path === [] ? 'new' : end($path));
})->with([
    'new to ready' => [[], 'ready'],
    'new to collected' => [[], 'collected'],
    'back to new' => [['picking'], 'new'],
    'collected to cancelled' => [['picking', 'ready', 'collected'], 'cancelled'],
    'cancelled to picking' => [['cancelled'], 'picking'],
    'not a status' => [[], 'shipped'],
]);

test('cancelling needs a reason the customer is told', function () {
    ($this->move)($this->order, 'cancelled')->assertSessionHasErrors('reason');
    ($this->move)($this->order, 'cancelled', ['reason' => 'no'])->assertSessionHasErrors('reason');
    ($this->move)($this->order, 'cancelled', ['reason' => str_repeat('x', 256)])->assertSessionHasErrors('reason');
    expect(($this->statusOf)($this->order))->toBe(OnlineOrderStatus::New);

    ($this->move)($this->order, 'cancelled', ['reason' => 'Sorry, some items are out of stock.'])
        ->assertSessionHasNoErrors()->assertSessionHas('success', 'W-0001 is cancelled. Asha P. is emailed with your reason.');
    $order = OnlineOrder::withoutCompanyScope()->findOrFail($this->order->id);
    expect($order->status)->toBe(OnlineOrderStatus::Cancelled)->and($order->cancel_reason)->toBe('Sorry, some items are out of stock.');
});

test('owners switch "Taking orders" on and off; a one-shop manager cannot', function () {
    $this->actingAs($this->owner)->from('/app/online-orders')->put('/app/online-orders/taking', ['taking' => false])->assertRedirect('/app/online-orders');
    expect(OnlineStore::withoutCompanyScope()->findOrFail($this->f->store->id)->taking_orders)->toBeFalse();
    $this->actingAs($this->owner)->put('/app/online-orders/taking', ['taking' => true]);
    expect(OnlineStore::withoutCompanyScope()->findOrFail($this->f->store->id)->taking_orders)->toBeTrue();

    $manager = C::member($this->f->company, CompanyRole::Manager, $this->f->leeds->id);
    $this->actingAs($manager)->put('/app/online-orders/taking', ['taking' => false])->assertForbidden();
    expect(OnlineStore::withoutCompanyScope()->findOrFail($this->f->store->id)->taking_orders)->toBeTrue();
});

test('a one-shop manager sees and moves only their own shop\'s orders', function () {
    $bradford = C::member($this->f->company, CompanyRole::Manager, $this->f->bradford->id);

    $this->actingAs($bradford)->get('/app/online-orders?shop=all')
        ->assertInertia(fn (Assert $page) => $page->where('filters.shop', $this->f->bradford->id)->where('filters.shopLocked', true)
            ->where('board.columns.new.count', 0)->where('options.shops', []));
    $this->actingAs($bradford)->get("/app/online-orders/{$this->order->id}")->assertNotFound();
    ($this->move)($this->order, 'picking', [], $bradford)->assertNotFound();
    expect(($this->statusOf)($this->order))->toBe(OnlineOrderStatus::New);

    $leeds = C::member($this->f->company, CompanyRole::Manager, $this->f->leeds->id);
    $this->actingAs($leeds)->get('/app/online-orders')->assertInertia(fn (Assert $page) => $page->where('board.columns.new.count', 1));
    ($this->move)($this->order, 'picking', [], $leeds)->assertSessionHasNoErrors();
});

test('business A never sees or changes business B\'s orders', function () {
    $other = OnlineStoreFixtures::make('Other Co', 'other-co');
    $theirs = $other->order([['productId' => $other->product('Tea'), 'quantity' => '1']], '2026-11-02T10:00:00Z')->order;
    $otherOwner = C::member($other->company, CompanyRole::Owner);

    $this->actingAs($this->owner)->get('/app/online-orders?shop=all')
        ->assertInertia(fn (Assert $page) => $page->where('board.columns.new.count', 1)->where('board.columns.new.cards.0.id', $this->order->id)
            ->where('board.stats.orders', 1));
    $this->actingAs($this->owner)->get("/app/online-orders/{$theirs->id}")->assertNotFound();
    $this->actingAs($this->owner)->get("/app/online-orders/{$theirs->id}/pick-list")->assertNotFound();
    ($this->move)($theirs, 'cancelled', ['reason' => 'Not ours to cancel'])->assertNotFound();
    expect(($this->statusOf)($theirs))->toBe(OnlineOrderStatus::New);

    $this->actingAs($otherOwner)->get('/app/online-orders?shop=all')
        ->assertInertia(fn (Assert $page) => $page->where('board.columns.new.cards.0.id', $theirs->id)->where('board.columns.new.count', 1));
    $this->actingAs($otherOwner)->get("/app/online-orders/{$this->order->id}")->assertNotFound();
    ($this->move)($this->order, 'picking', [], $otherOwner)->assertNotFound();
    expect(($this->statusOf)($this->order))->toBe(OnlineOrderStatus::New);
});

test('the sidebar counts new orders, and hides the item while the online store is switched off', function () {
    $this->actingAs($this->owner)->get('/app')
        ->assertInertia(fn (Assert $page) => $page->where('onlineOrdersNew', 1)->where('abilities', fn ($abilities) => collect($abilities)->contains('store.manage')));

    ($this->move)($this->order, 'picking');
    $this->actingAs($this->owner)->get('/app')->assertInertia(fn (Assert $page) => $page->where('onlineOrdersNew', 0));

    $this->actingAs(C::member($this->f->company, CompanyRole::Accountant))->get('/app')->assertInertia(fn (Assert $page) => $page->where('onlineOrdersNew', null));

    config(['online_store.enabled' => false]);
    $this->actingAs($this->owner)->get('/app')
        ->assertInertia(fn (Assert $page) => $page->where('onlineOrdersNew', null)->where('abilities', fn ($abilities) => ! collect($abilities)->contains('store.manage')));
});

test('a business without the add-on (so without a store) gets 404 (OS-6)', function () {
    $company = Company::factory()->create();
    $this->actingAs(C::member($company, CompanyRole::Owner))->get('/app/online-orders')->assertNotFound();
});
