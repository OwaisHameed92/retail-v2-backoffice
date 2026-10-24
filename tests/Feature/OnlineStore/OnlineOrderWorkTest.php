<?php

use App\Domain\OnlineStore\Actions\CloseUncollectedOrders;
use App\Domain\OnlineStore\Actions\PlaceCollectOrder;
use App\Domain\OnlineStore\Actions\RecordCardPayment;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Mail\OnlineOrderCancelledMail;
use App\Domain\OnlineStore\Mail\OnlineOrderReadyMail;
use App\Domain\OnlineStore\Mail\OnlineOrderReceivedMail;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineOrderLine;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\OnlineStore\FakeDnaClient;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;
use Tests\Feature\OnlineStore\StoreTestRoutes;

/*
 * OS-9 and the audit's order fixes on the portal board: delivery stages (new → picking → ready → out for delivery →
 * delivered), "not collected" (OSA-21) and undo, changing a line (OSA-18), cancel as spam (OSA-1), paid orders and
 * their refund note, resending the order link (OSA-22), and a board that stays open after the store goes off (OSA-5).
 */

beforeEach(function () {
    Mail::fake();
    StoreTestRoutes::ensure();
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    $this->f->deliveryOn();
    $this->beans = $this->f->product('Beans', ['sell_price' => '2.00']);
    $this->mince = $this->f->product('Mince', ['sell_price' => '9.80', 'is_weighed' => true, 'unit_code' => 'kg']);
    $this->owner = C::member($this->f->company, CompanyRole::Owner);
    $this->delivery = app(PlaceCollectOrder::class)->handle($this->f->store, $this->f->input([['productId' => $this->beans, 'quantity' => '6']], '2026-11-02T10:00:00Z', [
        'fulfilment' => 'delivery', 'paymentMethod' => 'payOnDelivery', 'deliveryLine1' => '1 Oak Road', 'deliveryTown' => 'Birmingham', 'deliveryPostcode' => 'B23 1AA',
    ]))->order;
    $this->move = fn (OnlineOrder $order, string $status, array $extra = []) => $this->actingAs($this->owner)
        ->from('/app/online-orders')->post("/app/online-orders/{$order->id}/status", ['status' => $status, ...$extra]);
    $this->fresh = fn (OnlineOrder $order) => OnlineOrder::withoutCompanyScope()->with('lines')->findOrFail($order->id);
});

test('a delivery goes new → picking → ready → out for delivery → delivered; collection moves are refused', function () {
    $this->actingAs($this->owner)->get("/app/online-orders/{$this->delivery->id}")->assertInertia(fn (Assert $p) => $p
        ->where('order.fulfilment', 'delivery')->where('order.deliveryAddress', '1 Oak Road, Birmingham, B23 1AA')->where('order.deliveryFee', '3.50')
        ->where('order.total', '15.50')->where('order.payment.label', 'Pay on delivery')->where('order.next', ['picking', 'cancelled']));

    ($this->move)($this->delivery, 'picking');
    ($this->move)($this->delivery, 'ready')->assertSessionHas('success', 'W-0001 is packed and ready to go out.');
    ($this->move)($this->delivery, 'collected')->assertSessionHasErrors('status');
    ($this->move)($this->delivery, 'outForDelivery')->assertSessionHas('success', 'W-0001 is out for delivery.');

    $this->actingAs($this->owner)->get('/app/online-orders')->assertInertia(fn (Assert $p) => $p
        ->where('board.columns.ready.cards.0.status', 'outForDelivery')->where('board.columns.ready.cards.0.fulfilment', 'delivery')
        ->where('board.columns.ready.cards.0.deliveryPostcode', 'B23 1AA'));
    ($this->move)($this->delivery, 'delivered')->assertSessionHas('success', 'W-0001 is delivered.');

    $order = ($this->fresh)($this->delivery);
    expect($order->status)->toBe(OnlineOrderStatus::Delivered)->and($order->out_for_delivery_at)->not->toBeNull()->and($order->delivered_at)->not->toBeNull();
    $this->actingAs($this->owner)->get('/app/online-orders')->assertInertia(fn (Assert $p) => $p->where('board.columns.done.cards.0.status', 'delivered'));
    // "Ready to collect" is a collection email: a delivery that is packed sends none.
    Mail::assertNotQueued(OnlineOrderReadyMail::class);
});

test('"not collected", and the last move can be undone for a few minutes', function () {
    $collect = $this->f->order([['productId' => $this->beans, 'quantity' => '1']], '2026-11-02T10:00:00Z')->order;
    ($this->move)($collect, 'picking');
    ($this->move)($collect, 'ready');
    ($this->move)($collect, 'notCollected')->assertSessionHas('success', 'W-0002 is marked as not collected.');
    expect(($this->fresh)($collect)->status)->toBe(OnlineOrderStatus::NotCollected);

    $this->actingAs($this->owner)->get("/app/online-orders/{$collect->id}")->assertInertia(fn (Assert $p) => $p->where('order.undo.to', 'ready'));
    $this->actingAs($this->owner)->post("/app/online-orders/{$collect->id}/undo")->assertSessionHas('success', 'W-0002 is back to ready to collect.');
    $back = ($this->fresh)($collect);
    expect($back->status)->toBe(OnlineOrderStatus::Ready)->and($back->not_collected_at)->toBeNull();
    // Only one step back.
    $this->actingAs($this->owner)->post("/app/online-orders/{$collect->id}/undo")->assertSessionHasErrors('status');

    ($this->move)($collect, 'collected');
    $this->travel(11)->minutes();
    $this->actingAs($this->owner)->post("/app/online-orders/{$collect->id}/undo")->assertSessionHasErrors(['status' => 'This move can no longer be undone.']);
});

test('ready orders nobody collects are closed as not collected after the set hours', function () {
    $collect = $this->f->order([['productId' => $this->beans, 'quantity' => '1']], '2026-11-02T10:00:00Z')->order;
    ($this->move)($collect, 'picking');
    ($this->move)($collect, 'ready');

    $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00:00', 'UTC'));
    expect(app(CloseUncollectedOrders::class)->handle())->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2026-11-04 11:00:00', 'UTC'));
    expect(app(CloseUncollectedOrders::class)->handle())->toBe(1)->and(($this->fresh)($collect)->status)->toBe(OnlineOrderStatus::NotCollected)
        ->and(($this->fresh)($this->delivery)->status)->toBe(OnlineOrderStatus::New);
});

test('staff mark a line not available or change it; the totals follow and the customer sees it', function () {
    $order = app(PlaceCollectOrder::class)->handle($this->f->store, $this->f->input([
        ['productId' => $this->beans, 'quantity' => '2'], ['productId' => $this->mince, 'quantity' => '1'],
    ], '2026-11-02T10:30:00Z', ['customerNote' => 'Thin slices please', 'substitutionsOk' => true]))->order;
    [$beans, $mince] = $order->lines->all();
    $line = fn (string $id, array $data) => $this->actingAs($this->owner)->from("/app/online-orders/{$order->id}")->post("/app/online-orders/{$order->id}/lines/{$id}", $data);

    $line($beans->id, ['action' => 'unavailable'])->assertSessionHasNoErrors();
    $line($mince->id, ['action' => 'changed', 'quantity' => '0.75'])->assertSessionHas('success', 'W-0002 changed. New total £7.35.');
    $line($mince->id, ['action' => 'changed', 'quantity' => '0.3'])->assertSessionHasErrors(['quantity' => 'Enter the weight in steps of 0.25 kg.']);

    $fresh = ($this->fresh)($order);
    expect([$fresh->items_total, $fresh->grandTotal()])->toBe(['7.35', '7.35'])
        ->and($fresh->lines[0]->outcome?->value)->toBe('unavailable')->and($fresh->lines[0]->line_total)->toBe('0.00')
        ->and($fresh->lines[1]->ordered_quantity)->toBe('1.0000')->and($fresh->lines[1]->quantity)->toBe('0.7500')
        ->and($fresh->customer_note)->toBe('Thin slices please')->and($fresh->substitutions_ok)->toBeTrue();

    $line($beans->id, ['action' => 'restore'])->assertSessionHasNoErrors();
    expect(($this->fresh)($order)->items_total)->toBe('11.35');

    ($this->move)($order, 'picking');
    ($this->move)($order, 'ready');
    ($this->move)($order, 'collected');
    $line($beans->id, ['action' => 'unavailable'])->assertSessionHasErrors(['line' => 'Items can be changed only before the order is handed over.']);
});

test('a paid order can only go down; cancelling it says the refund is made in the DNA portal', function () {
    FakeDnaClient::bind();
    $this->f->cardOn();
    $paid = app(PlaceCollectOrder::class)->handle($this->f->store, $this->f->input([['productId' => $this->beans, 'quantity' => '3']], '2026-11-02T11:00:00Z', ['paymentMethod' => 'cardOnline']))->order;
    $result = FakeDnaClient::result((string) $paid->payment_invoice_id, '6.00', OnlineStoreFixtures::DNA_SECRET);
    app(RecordCardPayment::class)->handle($this->f->store->id, $result);
    $line = $paid->lines->first();

    $this->actingAs($this->owner)->from('/x')->post("/app/online-orders/{$paid->id}/lines/{$line->id}", ['action' => 'changed', 'quantity' => '5'])
        ->assertSessionHasErrors(['quantity' => 'This order was paid by card online, so it can only go down. Add anything extra at the till.']);
    $this->actingAs($this->owner)->post("/app/online-orders/{$paid->id}/lines/{$line->id}", ['action' => 'changed', 'quantity' => '2'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->get("/app/online-orders/{$paid->id}")->assertInertia(fn (Assert $p) => $p->where('order.refundDue', '2.00')->where('order.paidAmount', '6.00'));

    ($this->move)($paid, 'cancelled', ['reason' => 'Shop closed early'])
        ->assertSessionHas('success', 'W-0002 is cancelled. Asha P. is emailed with your reason. It was paid by card online: refund it in your DNA portal.');
    $this->actingAs($this->owner)->get('/app/online-orders')->assertInertia(fn (Assert $p) => $p->where('board.columns.done.cards.0.payment.refundNeeded', true));
    $this->actingAs($this->owner)->get("/app/online-orders/{$paid->id}")->assertInertia(fn (Assert $p) => $p->where('order.refundDue', '6.00'));
});

test('an order cancelled as spam frees its slot and emails nobody', function () {
    ($this->move)($this->delivery, 'cancelled', ['spam' => true])->assertSessionHas('success', 'W-0001 is cancelled as a fake order. Nobody is emailed.');

    $order = ($this->fresh)($this->delivery);
    expect($order->status)->toBe(OnlineOrderStatus::Cancelled)->and($order->cancelled_as_spam)->toBeTrue()->and($order->cancel_reason)->toBe('Cancelled as a fake order');
    Mail::assertNotQueued(OnlineOrderCancelledMail::class);
});

test('the shop can send the customer a fresh order link', function () {
    $this->actingAs($this->owner)->post("/app/online-orders/{$this->delivery->id}/resend-link")
        ->assertSessionHas('success', 'W-0001: the order email with a fresh link is on its way to the customer.');

    Mail::assertQueued(OnlineOrderReceivedMail::class, fn (OnlineOrderReceivedMail $mail) => $mail->hasTo('asha@example.test')
        && str_contains((string) $mail->data->orderUrl, '/o/'.$this->delivery->id) && $mail->data->isDelivery()
        && $mail->data->deliveryAddress === '1 Oak Road, Birmingham, B23 1AA' && $mail->data->grandTotal === '15.50');
});

test('after the add-on goes off the board stays open for the open orders, then closes once they are done (OSA-5)', function () {
    OnlineStore::withoutCompanyScope()->whereKey($this->f->store->id)->update(['addon_on' => false]);

    $this->actingAs($this->owner)->get('/app/online-orders')->assertOk()->assertInertia(fn (Assert $p) => $p->where('storeAvailable', false)->where('board.columns.new.count', 1));
    $this->actingAs($this->owner)->get("/app/online-orders/{$this->delivery->id}")->assertOk();
    $this->get('/store/kirkgate')->assertNotFound();

    ($this->move)($this->delivery, 'cancelled', ['reason' => 'The online shop has closed.'])->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->get('/app/online-orders')->assertNotFound();
});

test('another business cannot see, change, undo or resend these orders', function () {
    $b = OnlineStoreFixtures::make('Briggate Butchers', 'briggate');
    $bOwner = C::member($b->company, CompanyRole::Owner);
    $line = $this->delivery->lines->first();

    $this->actingAs($bOwner)->get("/app/online-orders/{$this->delivery->id}")->assertNotFound();
    $this->actingAs($bOwner)->post("/app/online-orders/{$this->delivery->id}/undo")->assertNotFound();
    $this->actingAs($bOwner)->post("/app/online-orders/{$this->delivery->id}/lines/{$line->id}", ['action' => 'unavailable'])->assertNotFound();
    $this->actingAs($bOwner)->post("/app/online-orders/{$this->delivery->id}/resend-link")->assertNotFound();
    auth()->guard('web')->logout();
    $this->post("/app/online-orders/{$this->delivery->id}/undo")->assertRedirect('/login');

    expect(OnlineOrderLine::withoutCompanyScope()->whereKey($line->id)->sole()->outcome)->toBeNull();
    Mail::assertQueuedCount(2);
});
