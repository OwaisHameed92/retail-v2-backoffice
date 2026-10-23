<?php

use App\Domain\OnlineStore\Actions\ChangeOnlineOrderStatus;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Events\OnlineOrderStatusChanged;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\Shared\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    $this->order = $this->f->order([['productId' => $this->f->product('Beans'), 'quantity' => '1']], '2026-11-02T10:00:00Z')->order;
    $this->move = fn (OnlineOrderStatus $to, ?string $reason = null, ?OnlineOrder $order = null) => app(ChangeOnlineOrderStatus::class)
        ->handle($this->f->company, ($order ?? $this->order)->id, $to, $reason);
});

test('new → picking → ready → collected, each stage stamping its time', function () {
    Event::fake([OnlineOrderStatusChanged::class]);

    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:15:00', 'UTC'));
    ($this->move)(OnlineOrderStatus::Picking);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 08:40:00', 'UTC'));
    ($this->move)(OnlineOrderStatus::Ready);
    $this->travelTo(CarbonImmutable::parse('2026-11-02 10:05:00', 'UTC'));
    $order = ($this->move)(OnlineOrderStatus::Collected)->refresh();

    expect($order->status)->toBe(OnlineOrderStatus::Collected)
        ->and($order->picked_at?->toIso8601ZuluString())->toBe('2026-11-02T08:15:00Z')
        ->and($order->ready_at?->toIso8601ZuluString())->toBe('2026-11-02T08:40:00Z')
        ->and($order->collected_at?->toIso8601ZuluString())->toBe('2026-11-02T10:05:00Z')
        ->and($order->cancelled_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'online_order.status_changed')->count())->toBe(3);

    Event::assertDispatchedTimes(OnlineOrderStatusChanged::class, 3);
    Event::assertDispatched(OnlineOrderStatusChanged::class, fn (OnlineOrderStatusChanged $e) => $e->from === OnlineOrderStatus::Ready && $e->order->status === OnlineOrderStatus::Collected);
});

test('cancel needs a reason and stores it with the time', function () {
    expect(fn () => ($this->move)(OnlineOrderStatus::Cancelled))->toThrow(ValidationException::class)
        ->and(fn () => ($this->move)(OnlineOrderStatus::Cancelled, '  '))->toThrow(ValidationException::class)
        ->and(fn () => ($this->move)(OnlineOrderStatus::Cancelled, str_repeat('x', 256)))->toThrow(ValidationException::class);

    $order = ($this->move)(OnlineOrderStatus::Cancelled, 'Out of stock today')->refresh();

    expect($order->status)->toBe(OnlineOrderStatus::Cancelled)->and($order->cancel_reason)->toBe('Out of stock today')
        ->and($order->cancelled_at?->toIso8601ZuluString())->toBe('2026-11-02T07:00:00Z');
});

test('only allowed moves: :dataset', function (array $path, OnlineOrderStatus $to) {
    foreach ($path as $step) {
        ($this->move)($step, $step === OnlineOrderStatus::Cancelled ? 'Shop closed' : null);
    }

    expect(fn () => ($this->move)($to, 'Some reason'))->toThrow(ValidationException::class);
})->with([
    'new to ready (skips picking)' => [[], OnlineOrderStatus::Ready],
    'new to collected' => [[], OnlineOrderStatus::Collected],
    'new to new' => [[], OnlineOrderStatus::New],
    'picking back to new' => [[OnlineOrderStatus::Picking], OnlineOrderStatus::New],
    'collected to cancelled' => [[OnlineOrderStatus::Picking, OnlineOrderStatus::Ready, OnlineOrderStatus::Collected], OnlineOrderStatus::Cancelled],
    'cancelled to picking' => [[OnlineOrderStatus::Cancelled], OnlineOrderStatus::Picking],
]);

test('the enum lists the moves', function () {
    expect(OnlineOrderStatus::New->next())->toBe([OnlineOrderStatus::Picking, OnlineOrderStatus::Cancelled])
        ->and(OnlineOrderStatus::Ready->canMoveTo(OnlineOrderStatus::Cancelled))->toBeTrue()
        ->and(OnlineOrderStatus::Collected->isOpen())->toBeFalse()
        ->and(OnlineOrderStatus::Cancelled->next())->toBe([]);
});

test('another business cannot move this order', function () {
    $other = OnlineStoreFixtures::make('Other Co', 'other-co');

    expect(fn () => app(ChangeOnlineOrderStatus::class)->handle($other->company, $this->order->id, OnlineOrderStatus::Picking))
        ->toThrow(ValidationException::class);
    expect(OnlineOrder::withoutCompanyScope()->find($this->order->id)?->status)->toBe(OnlineOrderStatus::New);
});
