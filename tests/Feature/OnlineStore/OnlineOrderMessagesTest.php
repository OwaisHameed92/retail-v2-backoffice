<?php

use App\Domain\Notifications\Models\AlertNotification;
use App\Domain\OnlineStore\Actions\ChangeOnlineOrderStatus;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Events\OnlineOrderPlaced;
use App\Domain\OnlineStore\Events\OnlineOrderStatusChanged;
use App\Domain\OnlineStore\Mail\NewOnlineOrderMail;
use App\Domain\OnlineStore\Mail\OnlineOrderCancelledMail;
use App\Domain\OnlineStore\Mail\OnlineOrderReadyMail;
use App\Domain\OnlineStore\Mail\OnlineOrderReceivedMail;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;
use Tests\Feature\OnlineStore\StoreTestRoutes;

/* OS-4: the shop's new order email and bell entries, and the customer's order emails (received, ready, cancelled). */

beforeEach(function () {
    Mail::fake();
    StoreTestRoutes::ensure();
    config(['app.url' => 'https://portal.example.test', 'sspos.portal_url' => 'https://portal.example.test', 'mail.from.address' => 'hello@switchandsave.test']);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    $this->owner = C::member($this->f->company, CompanyRole::Owner);
    $this->manager = C::member($this->f->company, CompanyRole::Manager);
    $this->place = fn () => $this->f->order([
        ['productId' => $this->f->product('Beans'), 'quantity' => '2'],
        ['productId' => $this->f->product('Lamb mince', ['is_weighed' => true, 'sell_price' => '11.00']), 'quantity' => '0.5'],
    ], '2026-11-02T10:00:00Z')->order;
    $this->move = fn (OnlineOrder $order, OnlineOrderStatus $to, ?string $reason = null) => app(ChangeOnlineOrderStatus::class)
        ->handle($this->f->company, $order->id, $to, $reason);
});

test('a new order emails the shop and the customer and tells the store\'s staff', function () {
    $order = ($this->place)();

    Mail::assertQueued(NewOnlineOrderMail::class, function (NewOnlineOrderMail $mail) use ($order) {
        $html = $mail->render();

        return $mail->hasTo('orders@example.test') && $mail->subjectLine() === 'New online order W-0001'
            && $mail->hasReplyTo('asha@example.test') && $mail->hasFrom('hello@switchandsave.test', 'Kirkgate Convenience')
            && str_contains($html, 'Monday 2 November, 10:00–10:30') && str_contains($html, 'Lamb mince') && str_contains($html, '0.5 kg')
            && str_contains($html, '07700 900123') && $mail->data->orderUrl === null
            && str_contains($html, "https://portal.example.test/app/online-orders/{$order->id}");
    });
    Mail::assertQueued(OnlineOrderReceivedMail::class, function (OnlineOrderReceivedMail $mail) use ($order) {
        $html = $mail->render();

        return $mail->hasTo('asha@example.test') && $mail->subjectLine() === "We've got your order W-0001"
            && $mail->hasReplyTo('orders@example.test') && $mail->hasFrom('hello@switchandsave.test', 'Kirkgate Convenience')
            && $mail->data->orderUrl !== null && str_contains($mail->data->orderUrl, "/store/o/{$order->id}") && str_contains($mail->data->orderUrl, 'signature=') && str_contains($mail->data->orderUrl, 'expires=')
            && str_contains($html, 'Hi Asha') && str_contains($html, 'Pay at the shop') && str_contains($html, 'Weighed items are priced on the scale');
    });
    Mail::assertQueuedCount(2);

    $bell = AlertNotification::withoutCompanyScope()->where('company_id', $this->f->company->id)->orderBy('user_id')->get();
    expect($bell->pluck('user_id')->all())->toBe([$this->owner->id, $this->manager->id])
        ->and($bell->first()?->title)->toBe('New online order W-0001')
        ->and($bell->first()?->alert_type)->toBe('onlineOrder')
        ->and($bell->first()?->url)->toBe("/app/online-orders/{$order->id}")
        ->and($bell->first()?->body)->toBe('Asha P. · 3 items · £7.50 · collect Mon 2 Nov, 10:00–10:30 at Leeds');
});

test('accountants, staff and another shop\'s manager get no bell entry', function () {
    $accountant = C::member($this->f->company, CompanyRole::Accountant);
    $staff = C::member($this->f->company, CompanyRole::Staff);
    $bradford = C::member($this->f->company, CompanyRole::Manager, $this->f->bradford->id);
    $leeds = C::member($this->f->company, CompanyRole::Manager, $this->f->leeds->id);

    ($this->place)();

    $users = AlertNotification::withoutCompanyScope()->pluck('user_id')->all();
    expect($users)->toContain($leeds->id)->not->toContain($accountant->id)->not->toContain($staff->id)->not->toContain($bradford->id);
});

test('without an order email the shop email goes to the business owner', function () {
    OnlineStore::withoutCompanyScope()->whereKey($this->f->store->id)->update(['order_email' => null]);

    ($this->place)();

    Mail::assertQueued(NewOnlineOrderMail::class, fn (NewOnlineOrderMail $mail) => $mail->hasTo($this->owner->email));
    Mail::assertQueued(OnlineOrderReceivedMail::class, fn (OnlineOrderReceivedMail $mail) => $mail->hasReplyTo($this->owner->email));
});

test('the customer hears when the order is ready and when it is cancelled, with the reason', function () {
    $order = ($this->place)();
    Mail::fake();

    ($this->move)($order, OnlineOrderStatus::Picking);
    Mail::assertNothingQueued();

    ($this->move)($order, OnlineOrderStatus::Ready);
    Mail::assertQueued(OnlineOrderReadyMail::class, fn (OnlineOrderReadyMail $mail) => $mail->hasTo('asha@example.test')
        && $mail->subjectLine() === 'Your order W-0001 is ready to collect' && $mail->hasReplyTo('orders@example.test')
        && str_contains($mail->render(), 'packed and waiting'));

    ($this->move)($order, OnlineOrderStatus::Collected);
    Mail::assertQueuedCount(1);

    $second = ($this->place)();
    Mail::fake();
    ($this->move)($second, OnlineOrderStatus::Cancelled, 'Sorry, we have run out of lamb mince.');
    Mail::assertQueued(OnlineOrderCancelledMail::class, fn (OnlineOrderCancelledMail $mail) => $mail->hasTo('asha@example.test')
        && $mail->subjectLine() === 'Your order W-0002 is cancelled'
        && str_contains($mail->render(), 'Sorry, we have run out of lamb mince.'));
    Mail::assertQueuedCount(1);
});

test('nothing is sent while the online store is switched off', function () {
    $order = ($this->place)();
    Mail::fake();
    AlertNotification::withoutCompanyScope()->delete();
    config(['online_store.enabled' => false]);

    OnlineOrderPlaced::dispatch($order);
    DB::table('online_orders')->where('id', $order->id)->update(['status' => 'ready']);
    OnlineOrderStatusChanged::dispatch(OnlineOrder::withoutCompanyScope()->findOrFail($order->id), OnlineOrderStatus::Picking);

    Mail::assertNothingQueued();
    expect(AlertNotification::withoutCompanyScope()->count())->toBe(0);
});

test('a business\'s messages never use another business\'s orders or people', function () {
    $other = OnlineStoreFixtures::make('Other Co', 'other-co');
    $otherOwner = C::member($other->company, CompanyRole::Owner);
    $other->order([['productId' => $other->product('Tea'), 'quantity' => '1']], '2026-11-02T10:00:00Z');

    Mail::assertQueued(NewOnlineOrderMail::class, fn (NewOnlineOrderMail $mail) => $mail->data->businessName === 'Other Co' && $mail->data->companyId === $other->company->id);
    expect(AlertNotification::withoutCompanyScope()->pluck('user_id')->all())->toBe([$otherOwner->id])
        ->and(AlertNotification::withoutCompanyScope()->value('company_id'))->toBe($other->company->id);
});

test('every online order email renders in the shop\'s name with the branded layout', function (string $class) {
    $mail = $class::sample();
    $html = (string) $mail->render();

    expect($html)->toContain('Kirkgate Convenience')->toContain('W-0042')->toContain('Switch &amp; Save')
        ->and($mail->renderText())->toContain('W-0042');
})->with([NewOnlineOrderMail::class, OnlineOrderReceivedMail::class, OnlineOrderReadyMail::class, OnlineOrderCancelledMail::class]);
