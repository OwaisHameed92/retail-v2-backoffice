<?php

use App\Domain\Purchasing\Reorder\DemandForecast;
use App\Domain\Purchasing\Reorder\ReorderCalculator;
use App\Domain\Purchasing\Reorder\ReorderInput;
use App\Domain\Purchasing\Reorder\Sources\LeadTimes;
use App\Domain\Shared\Support\Money;
use Carbon\CarbonImmutable;

/*
 * Module 6.4: the forecast and order maths on fixed figures (no database). "Today" is Thursday 24 September 2026;
 * history is the 8 full weeks before it (Thu 30 July … Wed 23 September).
 */

/** Daily units for the 56 days before $today: $perDay(CarbonImmutable $day, int $week) → units (week 1 = latest). */
function history(CarbonImmutable $today, callable $perDay, int $weeks = 8): array
{
    $out = [];

    for ($i = 1; $i <= 7 * $weeks; $i++) {
        $day = $today->subDays($i);
        $out[$day->toDateString()] = (string) $perDay($day, intdiv($i - 1, 7) + 1);
    }

    return $out;
}

function flat(string $perDay, CarbonImmutable $today): DemandForecast
{
    return DemandForecast::fromHistory(history($today, fn () => $perDay), $today, 8, 28);
}

beforeEach(function () {
    $this->today = CarbonImmutable::parse('2026-09-24');
});

test('the weekday pattern: a shop that sells 10 on Saturdays and 2 on other days', function () {
    $d = DemandForecast::fromHistory(history($this->today, fn (CarbonImmutable $day) => $day->isSaturday() ? 10 : 2), $this->today, 8, 28);

    expect($d->rate)->toBe('3.142857')                                     // 22 a week ÷ 7
        ->and($d->weeks)->toBe(8)
        ->and(Money::round($d->day(CarbonImmutable::parse('2026-09-26')), 2))->toBe('10.00')   // Saturday
        ->and(Money::round($d->day(CarbonImmutable::parse('2026-09-24')), 2))->toBe('2.00')    // Thursday
        ->and(Money::round($d->forecast($this->today, 7), 2))->toBe('22.00')                    // one whole week
        ->and(Money::round($d->forecast($this->today, 2), 2))->toBe('4.00')                     // Thu + Fri
        ->and($d->busiestDay())->toBe('Saturday');
});

test('a thin pattern is blended towards flat, and a shop shut on Sundays forecasts none then', function () {
    // 7 units in 8 weeks, all on Saturdays: blend 7/28 = 0.25, so Saturday = 1 + 0.25 × (7 − 1) = 2.5.
    $thin = DemandForecast::fromHistory(history($this->today, fn (CarbonImmutable $day, int $w) => $day->isSaturday() && $w <= 7 ? 1 : 0), $this->today, 8, 28);
    expect($thin->weekday[6])->toBe('2.500000')->and($thin->weekday[1])->toBe('0.750000');

    $closed = DemandForecast::fromHistory(history($this->today, fn (CarbonImmutable $day) => $day->isSunday() ? 0 : 6), $this->today, 8, 28);
    expect($closed->weekday[7])->toBe('0.000000')
        ->and(Money::round($closed->day(CarbonImmutable::parse('2026-09-27')), 2))->toBe('0.00')
        ->and($closed->quietDays())->toBe(['Sunday']);
});

test('recent weeks weigh more, and weeks before a new line first sold are left out', function () {
    // Last week 2 a day, the 7 before 1 a day: (8×14 + 7×(7+6+…+1)) ÷ (7 × 36) = 308 ÷ 252.
    $d = DemandForecast::fromHistory(history($this->today, fn ($day, int $w) => $w === 1 ? 2 : 1), $this->today, 8, 28);
    expect($d->rate)->toBe('1.222222')->and($d->lastWeekUnits)->toBe('14.000000');

    // Sold only in the last two weeks: averaged over 2 weeks (weights 2 and 1), not 8.
    $new = DemandForecast::fromHistory(history($this->today, fn ($day, int $w) => $w <= 2 ? 3 : 0), $this->today, 8, 28);
    expect($new->weeks)->toBe(2)->and($new->rate)->toBe('3.000000');

    expect(DemandForecast::fromHistory([], $this->today, 8, 28)->rate)->toBe('0');
});

test('the need is rounded up to whole cases', function () {
    // 2 a day; lead 2 + review 7 = 9 days → 18; spare 2 days = 4; on hand 5: 18 + 4 − 5 = 17 → 2 cases of 12.
    $result = ReorderCalculator::calculate(new ReorderInput($this->today, flat('2', $this->today), caseQty: 12, onHand: '5', leadDays: 2, reviewDays: 7));

    expect($result->cases)->toBe(2)
        ->and($result->units)->toBe('24.0000')
        ->and($result->forecast)->toBe('18.0000')
        ->and($result->safety)->toBe('4.0000')
        ->and($result->coverDays)->toBe('2.5')
        ->and($result->method)->toBe('forecast')
        ->and($result->reasons)->toContain('Needs 18 + 4 spare − 5 in stock and on the way = 17.')
        ->and($result->reasons)->toContain('Order 2 cases of 12 = 24 units, rounded up to whole cases.');

    // Exactly one case needed stays one case.
    expect(ReorderCalculator::calculate(new ReorderInput($this->today, flat('2', $this->today), caseQty: 10, onHand: '12', leadDays: 2, reviewDays: 7))->cases)->toBe(1);
});

test('open orders and transfers on the way are taken off', function () {
    $base = fn (string $onOrder, string $inTransit) => ReorderCalculator::calculate(new ReorderInput(
        $this->today, flat('2', $this->today), caseQty: 12, onHand: '5', onOrder: $onOrder, inTransit: $inTransit, leadDays: 2, reviewDays: 7,
    ));

    expect($base('12', '0')->cases)->toBe(1)          // 17 − 12 = 5 → 1 case
        ->and($base('12', '0')->position)->toBe('17.0000')
        ->and($base('12', '6')->cases)->toBe(0)       // 17 − 18 < 0: nothing
        ->and($base('12', '6')->reasons)->toContain('Enough stock: nothing to order this time.');
});

test('the minimum level is the spare stock, the maximum caps it, and the reorder quantity is a floor', function () {
    $min = ReorderCalculator::calculate(new ReorderInput($this->today, flat('2', $this->today), caseQty: 6, onHand: '5', minLevel: '10', leadDays: 2, reviewDays: 7));
    expect($min->safety)->toBe('10.0000')->and($min->cases)->toBe(4);  // 18 + 10 − 5 = 23 → 4 × 6

    $max = ReorderCalculator::calculate(new ReorderInput($this->today, flat('2', $this->today), caseQty: 12, onHand: '5', maxLevel: '20', leadDays: 2, reviewDays: 7));
    expect($max->cases)->toBe(1)->and($max->has('capped'))->toBeTrue();   // room 15 → rounded down to 1 case

    $floor = ReorderCalculator::calculate(new ReorderInput($this->today, flat('1', $this->today), caseQty: 1, onHand: '9', reorderQty: '24', leadDays: 2, reviewDays: 7));
    expect($floor->cases)->toBe(24);  // needs 2, but at least the reorder quantity
});

test('short-life products are capped at what can sell before the date', function () {
    // 2 a day, nothing on hand, 3-day life: arriving in 2 days it can sell 6 before the date, not 22.
    $six = ReorderCalculator::calculate(new ReorderInput($this->today, flat('2', $this->today), caseQty: 6, onHand: '0', leadDays: 2, reviewDays: 7, shelfLifeDays: 3));
    expect($six->cases)->toBe(1)->and($six->has('shortLife'))->toBeTrue()->and($six->has('runsOut'))->toBeTrue();

    // A case of 12 is more than it can sell, but the shop would run out: one case, flagged.
    $twelve = ReorderCalculator::calculate(new ReorderInput($this->today, flat('2', $this->today), caseQty: 12, onHand: '0', leadDays: 2, reviewDays: 7, shelfLifeDays: 3));
    expect($twelve->cases)->toBe(1)->and($twelve->has('wasteRisk'))->toBeTrue();

    // Long life: no cap.
    $long = ReorderCalculator::calculate(new ReorderInput($this->today, flat('2', $this->today), caseQty: 6, onHand: '0', leadDays: 2, reviewDays: 7, shelfLifeDays: 30));
    expect($long->cases)->toBe(4)->and($long->has('shortLife'))->toBeFalse();
});

test('a seasonal event raises the forecast on its days', function () {
    $events = [['name' => 'Halloween', 'from' => '2026-09-24', 'to' => '2026-09-28', 'factor' => '2.0000', 'basis' => 'lastYear']];
    $result = ReorderCalculator::calculate(new ReorderInput($this->today, flat('2', $this->today), caseQty: 1, onHand: '0', leadDays: 2, reviewDays: 7, events: $events));

    // 5 event days at 4 + 4 normal days at 2 = 28.
    expect($result->forecast)->toBe('28.0000')
        ->and($result->has('seasonal'))->toBeTrue()
        ->and(implode(' ', $result->reasons))->toContain('Halloween ×2 from last year\'s sales');
});

test('no sales history falls back to the min/max levels', function () {
    $levels = ReorderCalculator::calculate(new ReorderInput($this->today, DemandForecast::none(), caseQty: 12, onHand: '2', minLevel: '5', maxLevel: '24', leadDays: 2, reviewDays: 7));
    expect($levels->cases)->toBe(2)->and($levels->method)->toBe('levels')->and($levels->has('noHistory'))->toBeTrue();

    $none = ReorderCalculator::calculate(new ReorderInput($this->today, DemandForecast::none(), caseQty: 12, onHand: '2', leadDays: 2, reviewDays: 7));
    expect($none->cases)->toBe(0)->and($none->method)->toBe('none');
});

test('sales jumping or dropping, and too much stock, are flagged', function () {
    $spike = DemandForecast::fromHistory(history($this->today, fn ($day, int $w) => $w === 1 ? 5 : 1), $this->today, 8, 28);
    expect(ReorderCalculator::calculate(new ReorderInput($this->today, $spike, caseQty: 1, onHand: '100'))->flags)->toContain('spike');

    $slowing = DemandForecast::fromHistory(history($this->today, fn ($day, int $w) => $w === 1 ? 0 : 3), $this->today, 8, 28);
    $result = ReorderCalculator::calculate(new ReorderInput($this->today, $slowing, caseQty: 1, onHand: '500'));
    expect($result->flags)->toContain('slowing')->toContain('overstock');
});

test('the lead time median rounds up', function () {
    expect(LeadTimes::median([3, 1, 2]))->toBe(2)->and(LeadTimes::median([2, 3]))->toBe(3)->and(LeadTimes::median([4]))->toBe(4);
});
