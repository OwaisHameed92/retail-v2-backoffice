<?php

use App\Domain\Anomalies\Enums\AnomalyKind;
use App\Domain\Anomalies\Enums\AnomalySeverity;
use App\Domain\Anomalies\Models\Anomaly;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Anomalies\AnomalyFixtures as F;
use Tests\Feature\TillData\TillFixtures as T;

/*
 * Module 6.6: each detector on fixtures — a true positive, and no alarm on normal noise (a figure inside the shop's or
 * person's usual wobble, a thin baseline, a shop whose normal is different).
 */

beforeEach(function () {
    Mail::fake();
    [$this->company] = T::tenant();
    $this->id = $this->company->id;
    F::staff($this->id);
    $this->findings = fn (?AnomalyKind $kind = null) => Anomaly::withoutCompanyScope()->where('company_id', $this->id)
        ->when($kind !== null, fn ($q) => $q->where('kind', $kind->value))->get();
});

/** Three cashiers at Leeds, 15 sales a day for 8 weeks, with 0–2 voids a day (a noisy but normal pattern). */
function noisyTeam(string $companyId): void
{
    $pattern = [0, 1, 2, 1, 0, 1, 2, 0];

    foreach (F::baselineDays() as $i => $day) {
        foreach ([F::STAFF_A, F::STAFF_B, F::STAFF_C] as $s => $user) {
            F::sales($companyId, T::LEEDS, $day, 10, $user, 15);
            F::sales($companyId, T::LEEDS, $day, 11, $user, $pattern[($i + $s) % 8], ['status' => 'voided']);
        }
    }
}

test('a cashier voiding far more than their own normal and their team\'s is flagged; the top of the usual wobble is not', function () {
    noisyTeam($this->id);
    foreach ([F::STAFF_A => 8, F::STAFF_B => 2, F::STAFF_C => 3] as $user => $voids) {
        F::sales($this->id, T::LEEDS, F::DAY, 10, $user, 15);
        F::sales($this->id, T::LEEDS, F::DAY, 11, $user, $voids, ['status' => 'voided']);
    }
    F::flush();

    F::detect('daily');

    $rows = ($this->findings)();
    expect($rows)->toHaveCount(1);
    $a = $rows->first();
    expect($a->kind)->toBe(AnomalyKind::StaffVoids)
        ->and($a->subject_id)->toBe(F::STAFF_A)
        ->and($a->subject_name)->toBe('Staff A')
        ->and($a->staff_level)->toBeTrue()
        ->and($a->severity)->toBe(AnomalySeverity::Medium) // above both their own norm and the team's: one level up
        ->and($a->branch_id)->toBe(T::LEEDS)
        ->and($a->trading_day)->toStartWith(F::DAY)
        ->and($a->title)->toBe('Staff A: 8 voids (£80.00) at Leeds on Wed 7 Oct')
        ->and($a->facts[3])->toMatchArray(['label' => 'Voids per 100 sales', 'value' => '53.3', 'usual' => '6.7', 'peers' => '6.7'])
        ->and($a->links[0]['href'])->toBe('/app/sales?from=2026-10-07&to=2026-10-07&shop='.T::LEEDS.'&staff='.F::STAFF_A.'&status=voided')
        ->and(collect($a->links)->pluck('href'))->toContain('/app/staff/'.F::STAFF_A.'/edit');
});

test('no-sale drawer opens and manual discounts are judged per staff member against the team', function () {
    noisyTeam($this->id);
    foreach (F::baselineDays() as $i => $day) {
        F::exceptions($this->id, T::LEEDS, $day, [F::STAFF_A, F::STAFF_B, F::STAFF_C][$i % 3], 'NoSale', $i % 2);
    }
    foreach ([F::STAFF_A, F::STAFF_B, F::STAFF_C] as $user) {
        F::sales($this->id, T::LEEDS, F::DAY, 10, $user, 15);
    }
    F::flush();
    F::exceptions($this->id, T::LEEDS, F::DAY, F::STAFF_B, 'NoSale', 12);
    F::exceptions($this->id, T::LEEDS, F::DAY, F::STAFF_C, 'NoSale', 1);
    // Staff A gives £40 off by hand on one sale (nobody discounts by hand normally); £10 by C stays under the minimum.
    foreach ([[F::STAFF_A, '40.00'], [F::STAFF_C, '10.00']] as [$user, $off]) {
        $sale = Ulid::new();
        F::sales($this->id, T::LEEDS, F::DAY, 12, $user, 1, ['id' => $sale]);
        F::flush();
        DB::table('sale_lines')->insert(['id' => Ulid::new(), 'company_id' => $this->id, 'branch_id' => T::LEEDS,
            'sale_id' => $sale, 'qty' => '1', 'line_discount' => $off, 'discount_source' => 'manual', 'goods_total' => '10.00']);
    }

    F::detect('daily');

    $rows = ($this->findings)()->keyBy(fn (Anomaly $a) => $a->kind->value);
    expect($rows)->toHaveCount(2)
        ->and($rows['staffNoSales']->subject_id)->toBe(F::STAFF_B)
        ->and($rows['staffNoSales']->links[0]['href'])->toContain('/app/compliance/exceptions?')->toContain('type=NoSale')
        ->and($rows['staffDiscounts']->subject_id)->toBe(F::STAFF_A)
        ->and($rows['staffDiscounts']->facts[0]['value'])->toBe('£40.00');
});

test('a thin baseline (a new shop) never raises staff findings', function () {
    foreach (array_slice(F::baselineDays(), 0, 5) as $day) {
        F::sales($this->id, T::LEEDS, $day, 10, F::STAFF_A, 15);
        F::sales($this->id, T::LEEDS, $day, 10, F::STAFF_B, 15);
    }
    F::sales($this->id, T::LEEDS, F::DAY, 10, F::STAFF_A, 15);
    F::sales($this->id, T::LEEDS, F::DAY, 11, F::STAFF_A, 9, ['status' => 'voided']);
    F::flush();

    F::detect('daily');

    expect(($this->findings)())->toHaveCount(0);
});

test('hours with no sales when that hour always trades are a sales gap, updated as it grows', function () {
    $thursdays = array_map(fn (int $w) => CarbonImmutable::parse('2026-10-08')->subWeeks($w)->toDateString(), range(1, 8));
    foreach ($thursdays as $w => $day) {
        foreach (range(7, 21) as $h) {
            F::hourly($this->id, T::LEEDS, $day, $h, 10);
            // Bradford's 13:00 and 14:00 trade only every other week: no reliable normal.
            if (! in_array($h, [13, 14], true) || $w % 2 === 0) {
                F::hourly($this->id, T::BRADFORD, $day, $h, 10);
            }
        }
    }
    foreach (range(7, 11) as $h) {
        F::hourly($this->id, T::LEEDS, '2026-10-08', $h, 10);
    }
    foreach (range(7, 12) as $h) {
        F::hourly($this->id, T::BRADFORD, '2026-10-08', $h, 10);
    }

    F::detect('hourly');

    $rows = ($this->findings)(AnomalyKind::SalesGap);
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->branch_id)->toBe(T::LEEDS)
        ->and($rows->first()->severity)->toBe(AnomalySeverity::High)
        ->and($rows->first()->title)->toBe('Leeds: no sales since 12:00 today')
        ->and($rows->first()->facts[0]['value'])->toBe('3 (12:00–15:00)')
        ->and($rows->first()->facts[1])->toMatchArray(['value' => '0', 'usual' => '30']);

    F::detect('hourly', '2026-10-08 16:20');

    $rows = ($this->findings)(AnomalyKind::SalesGap);
    expect($rows)->toHaveCount(1)->and($rows->first()->facts[0]['value'])->toBe('4 (12:00–16:00)');
});

test('no sales gap while the shop is closed by its opening hours', function () {
    F::hours($this->id, T::LEEDS, '07:00', '12:00');
    foreach (range(1, 8) as $w) {
        foreach (range(7, 21) as $h) {
            F::hourly($this->id, T::LEEDS, CarbonImmutable::parse('2026-10-08')->subWeeks($w)->toDateString(), $h, 10);
        }
    }
    foreach (range(7, 11) as $h) {
        F::hourly($this->id, T::LEEDS, '2026-10-08', $h, 10);
    }

    F::detect('hourly');

    expect(($this->findings)(AnomalyKind::SalesGap))->toHaveCount(0);
});

test('a day far below the usual weekday is a sales drop; a mildly quiet day is not', function () {
    $wednesdays = array_map(fn (int $w) => CarbonImmutable::parse(F::DAY)->subWeeks($w)->toDateString(), range(1, 8));
    foreach ($wednesdays as $w => $day) {
        F::daily($this->id, T::LEEDS, $day, (string) (950 + 20 * $w), 100);
        F::daily($this->id, T::BRADFORD, $day, (string) (480 + 10 * $w), 50);
    }
    F::daily($this->id, T::LEEDS, F::DAY, '200.00', 20);
    F::daily($this->id, T::BRADFORD, F::DAY, '420.00', 44);

    F::detect('daily');

    $rows = ($this->findings)(AnomalyKind::SalesDrop);
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->branch_id)->toBe(T::LEEDS)
        ->and($rows->first()->severity)->toBe(AnomalySeverity::Medium)
        ->and($rows->first()->facts[0])->toMatchArray(['value' => '£200.00', 'usual' => '£1,020.00']);
});

test('a cashier short at cash-up again and again is flagged; one short shift and a till shared by one person are not', function () {
    foreach ([0, 5, 10, 20] as $i => $back) {
        F::shift($this->id, T::LEEDS, CarbonImmutable::parse(F::DAY)->subDays($back)->toDateString(), F::STAFF_A, '-10.00');
        F::shift($this->id, T::LEEDS, CarbonImmutable::parse(F::DAY)->subDays($back + 1)->toDateString(), F::STAFF_A, '0.50');
    }
    foreach (range(1, 10) as $back) {
        F::shift($this->id, T::LEEDS, CarbonImmutable::parse(F::DAY)->subDays($back)->toDateString(), F::STAFF_B, $back === 3 ? '-12.00' : '1.00', T::TILL_2);
        F::shift($this->id, T::LEEDS, CarbonImmutable::parse(F::DAY)->subDays($back)->toDateString(), F::STAFF_C, '-1.00', T::TILL_2);
    }

    F::detect('daily');

    $rows = ($this->findings)();
    expect($rows)->toHaveCount(1);
    $a = $rows->first();
    expect($a->kind)->toBe(AnomalyKind::StaffCashShortfalls)
        ->and($a->subject_id)->toBe(F::STAFF_A)
        ->and($a->severity)->toBe(AnomalySeverity::Medium)
        ->and($a->title)->toBe('Staff A: 4 cash shortfalls in 28 days (£40.00)')
        ->and($a->links[0]['href'])->toStartWith('/app/cash/shifts/');
});

test('price override, negative stock and unlinked refund spikes are flagged against the shop\'s own trading days', function () {
    foreach (array_slice(F::baselineDays(), 0, 30) as $i => $day) {
        F::daily($this->id, T::LEEDS, $day, '1000.00', 100);
        F::daily($this->id, T::BRADFORD, $day, '1000.00', 100);
        F::exceptions($this->id, T::LEEDS, $day, F::STAFF_A, 'PriceOverride', $i % 3);
        if ($i % 2 === 0) {
            F::goneNegative($this->id, T::LEEDS, $day, 'P'.$i);
            F::sales($this->id, T::LEEDS, $day, 12, F::STAFF_A, 1, ['type' => 'refund', 'total' => '-8.00']);
        }
    }
    F::daily($this->id, T::LEEDS, F::DAY, '1000.00', 100);
    F::daily($this->id, T::BRADFORD, F::DAY, '1000.00', 100);
    F::exceptions($this->id, T::LEEDS, F::DAY, F::STAFF_A, 'PriceOverride', 12);
    F::exceptions($this->id, T::BRADFORD, F::DAY, F::STAFF_A, 'PriceOverride', 3);
    foreach (range(1, 8) as $p) {
        F::goneNegative($this->id, T::LEEDS, F::DAY, 'NEG'.$p);
    }
    F::sales($this->id, T::LEEDS, F::DAY, 12, F::STAFF_A, 3, ['type' => 'refund', 'total' => '-40.00']);
    // Bradford: a small unreceipted refund and a large refund of a known sale — normal.
    F::sales($this->id, T::BRADFORD, F::DAY, 12, F::STAFF_A, 1, ['type' => 'refund', 'total' => '-15.00']);
    F::sales($this->id, T::BRADFORD, F::DAY, 12, F::STAFF_A, 1, ['type' => 'refund', 'total' => '-200.00', 'original_sale_id' => 'ORIGINALSALE0000000000001']);
    F::flush();

    F::detect('daily');

    $rows = ($this->findings)()->keyBy(fn (Anomaly $a) => $a->kind->value);
    expect($rows->keys()->sort()->values()->all())->toBe(['negativeStock', 'priceOverrides', 'unlinkedRefunds'])
        ->and($rows->every(fn (Anomaly $a) => $a->branch_id === T::LEEDS && ! $a->staff_level))->toBeTrue()
        ->and($rows['priceOverrides']->facts[0]['value'])->toBe('12')
        ->and($rows['negativeStock']->facts[0])->toMatchArray(['value' => '8', 'usual' => '0.5'])
        ->and($rows['unlinkedRefunds']->facts[1])->toMatchArray(['value' => '£120.00'])
        ->and($rows['unlinkedRefunds']->links[0]['href'])->toContain('status=refunds');
});

test('sales outside opening hours are flagged, except within the grace and at a shop that always trades late', function () {
    F::hours($this->id, T::LEEDS, '08:00', '20:00');
    F::hours($this->id, T::BRADFORD, '08:00', '20:00');
    foreach (array_slice(F::baselineDays(), 0, 20) as $day) {
        F::sales($this->id, T::LEEDS, $day, 12, F::STAFF_A, 2);
        F::sales($this->id, T::BRADFORD, $day, 20, F::STAFF_B, 2, ['minute' => 50]);
    }
    F::sales($this->id, T::LEEDS, F::DAY, 12, F::STAFF_A, 5);
    F::sales($this->id, T::LEEDS, F::DAY, 20, F::STAFF_A, 1, ['minute' => 10]); // inside the 15-minute grace
    F::sales($this->id, T::LEEDS, F::DAY, 22, F::STAFF_A, 4, ['minute' => 30]);
    F::sales($this->id, T::BRADFORD, F::DAY, 22, F::STAFF_B, 4, ['minute' => 30]);
    F::flush();

    F::detect('daily');

    $rows = ($this->findings)(AnomalyKind::OutOfHours);
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->branch_id)->toBe(T::LEEDS)
        ->and($rows->first()->facts)->toBe([
            ['label' => 'Sales outside hours', 'value' => '4'],
            ['label' => 'Value', 'value' => '£40.00'],
            ['label' => 'Opening hours that day', 'value' => '08:00–20:00'],
            ['label' => 'First and last', 'value' => '22:30 and 22:30'],
        ]);
});

test('detection is per business: another business\'s rows never raise findings for this one', function () {
    $other = Company::factory()->withBranch('OTH', 'Other shop', 1)->create();
    $otherShop = Branch::withoutCompanyScope()->where('company_id', $other->id)->firstOrFail();
    foreach (range(1, 8) as $w) {
        F::daily($other->id, $otherShop->id, CarbonImmutable::parse(F::DAY)->subWeeks($w)->toDateString(), '1000.00', 100);
        F::daily($this->id, T::LEEDS, CarbonImmutable::parse(F::DAY)->subWeeks($w)->toDateString(), '1000.00', 100);
    }
    F::daily($this->id, T::LEEDS, F::DAY, '1000.00', 100);

    F::detect('daily');

    expect(($this->findings)())->toHaveCount(0)
        ->and(Anomaly::withoutCompanyScope()->where('company_id', $other->id)->pluck('branch_id')->all())->toBe([$otherShop->id]);
});
