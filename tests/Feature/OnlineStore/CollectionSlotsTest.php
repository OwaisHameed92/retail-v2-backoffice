<?php

use App\Domain\OnlineStore\Actions\ChangeOnlineOrderStatus;
use App\Domain\OnlineStore\Actions\SaveStoreShop;
use App\Domain\OnlineStore\Data\CollectionSlot;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Queries\CollectionSlots;
use Carbon\CarbonImmutable;
use Tests\Feature\OnlineStore\OnlineStoreFixtures;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00:00', 'UTC'));
    $this->f = OnlineStoreFixtures::make();
    $this->slots = app(CollectionSlots::class);
    $this->labels = fn (string $day, ?OnlineStoreShop $shop = null) => array_map(fn (CollectionSlot $s) => $s->label, $this->slots->handle($shop ?? $this->f->shop, $day));
});

test('slots run from opening to closing, slot-long, the first one after the prep time (winter: shop time is UTC)', function () {
    $slots = $this->slots->handle($this->f->shop, '2026-11-02');

    expect($slots)->toHaveCount(24)
        ->and($slots[0]->toArray())->toBe(['from' => '2026-11-02T08:00:00Z', 'to' => '2026-11-02T08:30:00Z', 'label' => '08:00–08:30', 'available' => true])
        ->and([$slots[0]->taken, $slots[0]->capacity])->toBe([0, 2])
        ->and(end($slots)->label)->toBe('19:30–20:00');

    $this->travelTo(CarbonImmutable::parse('2026-11-02 09:10:00', 'UTC'));
    expect(($this->labels)('2026-11-02')[0])->toBe('10:00–10:30');
});

test('summer: slots are London time and stored in UTC', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-06 05:00:00', 'UTC'));

    $first = $this->slots->handle($this->f->shop, '2026-07-06')[0];

    expect($first->label)->toBe('08:00–08:30')
        ->and($first->from->toIso8601ZuluString())->toBe('2026-07-06T07:00:00Z')
        ->and($first->to->toIso8601ZuluString())->toBe('2026-07-06T07:30:00Z');
});

test('across the October clock change each day keeps its wall-clock hours', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-23 12:00:00', 'UTC'));

    $saturday = $this->slots->handle($this->f->shop, '2026-10-24')[0];
    $sunday = $this->slots->handle($this->f->shop, '2026-10-25')[0];

    expect($saturday->label)->toBe('08:00–08:30')->and($saturday->from->toIso8601ZuluString())->toBe('2026-10-24T07:00:00Z')
        ->and($sunday->label)->toBe('08:00–08:30')->and($sunday->from->toIso8601ZuluString())->toBe('2026-10-25T08:00:00Z');
});

test('a day without hours, a past day, a day too far ahead or a bad date has none', function () {
    app(SaveStoreShop::class)->handle($this->f->company, $this->f->leeds->id, OnlineStoreFixtures::shopInput([
        'opening_hours' => [...array_fill_keys(OnlineStoreShop::WEEKDAYS, ['open' => '08:00', 'close' => '20:00']), 'sunday' => null],
    ]));
    $shop = $this->f->shop->refresh();

    expect(($this->labels)('2026-11-08', $shop))->toBe([])
        ->and(($this->labels)('2026-11-01', $shop))->toBe([])
        ->and(($this->labels)('2026-11-09', $shop))->toBe([])
        ->and(($this->labels)('2026-11-07', $shop))->not->toBe([])
        ->and(($this->labels)('2026-02-30', $shop))->toBe([])
        ->and(($this->labels)('not a day', $shop))->toBe([]);
});

test('the last slot ends by closing time; longer slots and prep minutes are honoured', function () {
    app(SaveStoreShop::class)->handle($this->f->company, $this->f->leeds->id, OnlineStoreFixtures::shopInput([
        'opening_hours' => array_fill_keys(OnlineStoreShop::WEEKDAYS, ['open' => '09:00', 'close' => '11:30']),
        'slot_minutes' => 60, 'prep_minutes' => 150,
    ]));

    // Now 07:00 + 150 minutes: nothing before 09:30. 09:00–10:00 is too soon; 11:00–12:00 would end after closing.
    expect(($this->labels)('2026-11-02', $this->f->shop->refresh()))->toBe(['10:00–11:00'])
        ->and(($this->labels)('2026-11-03', $this->f->shop))->toBe(['09:00–10:00', '10:00–11:00']);
});

test('capacity counts the slot\'s orders that are not cancelled', function () {
    $id = $this->f->product('Beans');
    $line = [['productId' => $id, 'quantity' => '1']];
    $first = $this->f->order($line, '2026-11-02T10:00:00Z');
    $this->f->order($line, '2026-11-02T10:00:00Z');

    $slot = $this->slots->find($this->f->shop, CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC'));
    expect($slot?->taken)->toBe(2)->and($slot?->available())->toBeFalse();

    app(ChangeOnlineOrderStatus::class)->handle($this->f->company, $first->order->id, OnlineOrderStatus::Cancelled, 'Customer rang');
    $slot = $this->slots->find($this->f->shop, CarbonImmutable::parse('2026-11-02 10:00:00', 'UTC'));
    expect($slot?->taken)->toBe(1)->and($slot?->available())->toBeTrue();
});

test('find: only a slot that starts exactly then', function () {
    expect($this->slots->find($this->f->shop, CarbonImmutable::parse('2026-11-02T10:00:00+00:00')))->not->toBeNull()
        ->and($this->slots->find($this->f->shop, CarbonImmutable::parse('2026-11-02T10:15:00Z')))->toBeNull()
        ->and($this->slots->find($this->f->shop, CarbonImmutable::parse('2026-11-02T07:00:00Z')))->toBeNull();
});
