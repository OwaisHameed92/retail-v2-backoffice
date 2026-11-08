<?php

use App\Domain\Salon\Availability\FreeTimes;
use App\Domain\Salon\Availability\LondonClock;
use App\Domain\Salon\Availability\PageMenu;
use App\Domain\Salon\Availability\StylistChoice;
use App\Domain\Salon\Online\BookingPageReader;
use Carbon\CarbonImmutable;
use Tests\Feature\Salon\SalonOnline as S;

/*
 * W3a, salon pack README §10 (model "a"): the booking page offers only the till's published ranges. One stylist, "Any
 * stylist" (union), a basket on one stylist's grid, minimum notice, never the clock-change hour, Europe/London times.
 * The sample page (2026-11-09, GMT): Ann does the cut 10:00-17:30, Ben 10:00-10:30 and 11:30-17:30; Ann does Short
 * (45 min) 10:00-17:15 and Long (60) 10:00-17:00; Beard trim is an add-on (+15) with no grid of its own.
 */

function salonMenu(array $set = []): PageMenu
{
    return new PageMenu(BookingPageReader::read(S::page($set))['page']);
}

function salonTimes(PageMenu $menu, array $options, array $addOns, string $who, string $now = '2026-11-09T07:00:00Z', array $holds = [], string $date = '2026-11-09'): array
{
    $basket = $menu->basket($options, $addOns);

    return (new FreeTimes($menu))->on($date, $basket, StylistChoice::candidates($menu, $basket, $who), CarbonImmutable::parse($now), $holds);
}

test('one option and a named stylist: every interval of their ranges, inclusive', function () {
    $ben = salonTimes(salonMenu(), [S::CUT], [], S::BEN);

    expect(array_slice($ben, 0, 4))->toBe(['10:00', '10:15', '10:30', '11:30'])
        ->and($ben)->not->toContain('10:45')->not->toContain('11:15')
        ->and(end($ben))->toBe('17:30')
        ->and(count($ben))->toBe(3 + 25);
});

test('Any stylist is the union of the stylists\' starts; a group wish is the union of that group', function () {
    $menu = salonMenu();
    $any = salonTimes($menu, [S::CUT], [], 'any');

    expect($any)->toContain('10:45')->toContain('11:00')->and(count($any))->toBe(31)
        ->and(salonTimes($menu, [S::CUT], [], 'male'))->toBe(salonTimes($menu, [S::CUT], [], S::BEN))
        ->and(salonTimes($menu, [S::CUT], [], 'female'))->toBe(salonTimes($menu, [S::CUT], [], S::ANN))
        // Ben does not do Short: a named Ben offers nothing, Any offers Ann's.
        ->and(salonTimes($menu, [S::SHORT], [], S::BEN))->toBe([])
        ->and(salonTimes($menu, [S::SHORT], [], 'any'))->toBe(salonTimes($menu, [S::SHORT], [], S::ANN));
});

test('a basket uses one stylist\'s grid: each next item must start on it at t + the minutes before it', function () {
    $menu = salonMenu();

    // Cut (30) + beard trim: the trim starts at t+30, on the cut's grid (it has none of its own).
    $ben = salonTimes($menu, [S::CUT], [S::BEARD], S::BEN);
    expect($ben)->toContain('10:00')->toContain('11:30')->toContain('17:00')
        ->not->toContain('10:15')->not->toContain('10:30')->not->toContain('17:15')->not->toContain('17:30');

    // Cut (30) then Short for Ann: t on the cut's grid and t+30 on Short's (10:00-17:15) → last 16:45.
    $ann = salonTimes($menu, [S::CUT, S::SHORT], [], S::ANN);
    expect($ann[0])->toBe('10:00')->and(end($ann))->toBe('16:45')
        // Any stylist for a basket: only Ann does both; Ben's cut grid alone never counts.
        ->and(salonTimes($menu, [S::CUT, S::SHORT], [], 'any'))->toBe($ann);

    // A stylist's own minutes count: Ann's cut is 45 minutes on her own price list here.
    $slow = salonMenu(['services.0.options.0.byStylist.0.durationMin' => 45]);
    expect(salonTimes($slow, [S::CUT, S::SHORT], [], S::ANN))->toContain('16:30')->not->toContain('16:45');

    // An add-on without its service, or something not on the page: no basket.
    expect($menu->basket([], [S::BEARD]))->toBeNull()->and($menu->basket(['01K9S0000000000000000SV999'], []))->toBeNull();
});

test('starts earlier than now + minNoticeMin are hidden', function () {
    $menu = salonMenu();

    // 09:00 + 120 minutes → 11:00 is the first (GMT: shop time = UTC).
    expect(salonTimes($menu, [S::CUT], [], S::ANN, '2026-11-09T09:00:00Z')[0])->toBe('11:00')
        ->and(salonTimes($menu, [S::CUT], [], S::ANN, '2026-11-09T09:01:00Z')[0])->toBe('11:15')
        ->and(salonTimes(salonMenu(['rules.minNoticeMin' => 0]), [S::CUT], [], S::ANN, '2026-11-09T09:01:00Z')[0])->toBe('10:00')
        ->and(salonTimes($menu, [S::CUT], [], S::ANN, '2026-11-09T16:00:00Z'))->toBe([]);
});

test('times are shop time (Europe/London) and the clock-change hour is never offered', function () {
    $day = fn (string $date, int $offset) => ['availability.days' => [[
        'date' => $date, 'utcOffsetMinutes' => $offset,
        'items' => [['productId' => S::CUT, 'stylists' => [['userId' => S::BEN, 'ranges' => ['00:00-03:00']]]]],
    ]]];

    // Spring forward (2027-03-28): 01:00–01:59 does not exist.
    $spring = salonTimes(salonMenu($day('2027-03-28', 0)), [S::CUT], [], S::BEN, '2027-03-20T00:00:00Z', date: '2027-03-28');
    expect($spring)->toContain('00:45')->toContain('02:00')->not->toContain('01:00')->not->toContain('01:30')->not->toContain('01:45')
        ->and(LondonClock::toUtc('2027-03-28', 120)?->toIso8601ZuluString())->toBe('2027-03-28T01:00:00Z');

    // Fall back (2026-10-25): 01:00–01:59 happens twice.
    $autumn = salonTimes(salonMenu($day('2026-10-25', 60)), [S::CUT], [], S::BEN, '2026-10-20T00:00:00Z', date: '2026-10-25');
    expect($autumn)->toContain('00:45')->toContain('02:00')->not->toContain('01:00')->not->toContain('01:45')
        ->and(LondonClock::toUtc('2026-10-25', 30)?->toIso8601ZuluString())->toBe('2026-10-24T23:30:00Z')
        ->and(LondonClock::toUtc('2026-10-25', 120)?->toIso8601ZuluString())->toBe('2026-10-25T02:00:00Z')
        ->and(LondonClock::toUtc('2026-07-01', 600)?->toIso8601ZuluString())->toBe('2026-07-01T09:00:00Z')
        ->and(LondonClock::toUtc('2026-11-09', 600)?->toIso8601ZuluString())->toBe('2026-11-09T10:00:00Z');
});

test('a time held for an earlier pending request of that stylist is not offered again', function () {
    $menu = salonMenu();
    $hold = [['userId' => S::BEN, 'from' => CarbonImmutable::parse('2026-11-09T11:30:00Z'), 'to' => CarbonImmutable::parse('2026-11-09T12:00:00Z')]];

    $ben = salonTimes($menu, [S::CUT], [], S::BEN, holds: $hold);
    expect($ben)->not->toContain('11:30')->toContain('12:00')->toContain('10:30')
        // Any stylist: Ann is still free at 11:30.
        ->and(salonTimes($menu, [S::CUT], [], 'any', holds: $hold))->toContain('11:30');
});

test('a date with no free start is left out', function () {
    $menu = salonMenu(['availability.days.1' => ['date' => '2026-11-10', 'utcOffsetMinutes' => 0, 'items' => []]]);
    $basket = $menu->basket([S::CUT], []);

    expect(array_column((new FreeTimes($menu))->days($basket, [S::ANN, S::BEN], CarbonImmutable::parse('2026-11-09T07:00:00Z')), 'date'))->toBe(['2026-11-09']);
});
