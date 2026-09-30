<?php

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Reporting\BusinessDashboardHelpers as H;
use Tests\Feature\Reporting\ReportsHelpers as R;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 4.8: the stock report (the tills' BranchProduct rows, now) and shifts / Z reports (Shift, ShiftTender,
 * ZReport rows). "Now" is Wed 23 Sept 2026 18:00 London.
 */

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-09-23 18:00', 'Europe/London'));
    [$this->kirkgate, $this->leeds, $this->bradford] = TillFixtures::tenant();
    $this->owner = R::member($this->kirkgate, CompanyRole::Owner);
    $this->other = Company::factory()->create(['name' => 'Other Stores']);
    $this->otherShop = Branch::factory()->forCompany($this->other)->create(['name' => 'Other shop']);
});

test('before any till sends stock the report says it comes with stock control', function () {
    $props = R::props($this->actingAs($this->owner)->get('/app/reports/stock'));

    expect($props['result']['available'])->toBeFalse()
        ->and($props['result']['notes'][0])->toContain('5.1')
        ->and($props['result']['tables'])->toBe([]);
});

test('stock on hand, low and out of stock and value at cost follow the till\'s rule, per shop, and never another business', function () {
    $id = $this->kirkgate->id;
    H::stock($id, TillFixtures::LEEDS, 'PLOW', '3');            // ≤ 5 (default threshold): low
    H::stock($id, TillFixtures::LEEDS, 'POUT', '0');            // out
    H::stock($id, TillFixtures::LEEDS, 'POK', '40', '10');      // above its own minimum 10
    H::stock($id, TillFixtures::BRADFORD, 'PBRD', '8', '10');   // below its minimum: low
    H::stock($id, TillFixtures::LEEDS, 'PUNT', '1', null, false); // not tracked: not listed
    H::stock($this->other->id, $this->otherShop->id, 'POTH', '1');
    DB::table('products')->where('id', 'POK')->update(['cost_price' => '0.4250']);
    DB::table('products')->where('id', 'PLOW')->update(['cost_price' => '1.0000']);

    $props = R::props($this->actingAs($this->owner)->get('/app/reports/stock'));
    expect(R::figure($props, 'lines')['value'])->toBe(4)
        ->and(R::figure($props, 'low')['value'])->toBe(3)
        ->and(R::figure($props, 'out')['value'])->toBe(1)
        ->and(R::figure($props, 'units')['value'])->toBe('51.0000')
        ->and(R::figure($props, 'value')['value'])->toBe('20.00'); // 40 × 0.425 + 3 × 1.00

    $lines = R::table($props, 'lines');
    expect(array_column($lines['rows'], 'status'))->toBe(['out', 'low', 'low', 'ok'])
        ->and($lines['rows'][0]['name'])->toBe('Product POUT')
        ->and($lines['pagination']['total'])->toBe(4);

    $low = R::props($this->actingAs($this->owner)->get('/app/reports/stock?view=low'));
    expect(R::table($low, 'lines')['pagination']['total'])->toBe(3)->and($low['filters']['view'])->toBe('low');

    // A one-shop manager of Bradford sees Bradford only; the CSV too.
    $manager = R::member($this->kirkgate, CompanyRole::Manager, TillFixtures::BRADFORD);
    $mine = R::props($this->actingAs($manager)->get('/app/reports/stock'));
    expect(R::figure($mine, 'lines')['value'])->toBe(1)->and(R::table($mine, 'lines')['rows'][0]['name'])->toBe('Product PBRD');
    expect($this->actingAs($manager)->get('/app/reports/stock/export')->streamedContent())->toContain('Product PBRD')->not->toContain('Product PLOW');

    // The other business sees only its own line.
    $theirs = R::props($this->actingAs(R::member($this->other, CompanyRole::Owner))->get('/app/reports/stock'));
    expect(R::figure($theirs, 'lines')['value'])->toBe(1);
});

test('shifts closed in the range: expected against counted, variance, the till\'s alert flag and the Z reports', function () {
    $id = $this->kirkgate->id;
    H::shift($id, TillFixtures::LEEDS, 'SHIFT00000000000000000001', '2026-09-23 16:00:00', '-2.50');
    H::shift($id, TillFixtures::BRADFORD, 'SHIFT00000000000000000002', '2026-09-22 16:00:00', '1.00');
    H::shift($id, TillFixtures::LEEDS, 'SHIFT00000000000000000003', '2026-09-10 16:00:00', '-9.00'); // outside the range
    H::shift($this->other->id, $this->otherShop->id, 'SHIFT00000000000000000009', '2026-09-23 16:00:00', '-50.00');
    DB::table('shifts')->where('id', 'SHIFT00000000000000000001')->update(['register_id' => TillFixtures::TILL_1, 'variance_total' => '-1.50', 'opening_float' => '100.00']);
    DB::table('shifts')->where('id', 'SHIFT00000000000000000002')->update(['register_id' => TillFixtures::BRADFORD_TILL, 'variance_total' => '2.00']);
    DB::table('shift_tenders')->where('shift_id', 'SHIFT00000000000000000001')->update(['expected' => '100.00', 'declared' => '97.50']);
    DB::table('z_reports')->insert([
        'id' => 'Z0000000000000000000000001', 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'register_id' => TillFixtures::TILL_1,
        'shift_id' => 'SHIFT00000000000000000001', 'sequence_no' => 41, 'period_start' => '2026-09-23 06:00:00', 'period_end' => '2026-09-23 16:00:00',
        'totals_json' => json_encode(['VarianceTotal' => -1.5, 'HasVarianceWarning' => true, 'Tenders' => []]),
    ]);

    $props = R::props($this->actingAs($this->owner)->get('/app/reports/shifts?period=last7Days'));
    expect(R::figure($props, 'shifts')['value'])->toBe(2)
        ->and(R::figure($props, 'cashVariance')['value'])->toBe('-1.50')   // −2.50 + 1.00, cash only
        ->and(R::figure($props, 'variance')['value'])->toBe('0.50')        // Shift.varianceTotal −1.50 + 2.00
        ->and(R::figure($props, 'short')['value'])->toBe(1)
        ->and(R::figure($props, 'warnings')['value'])->toBe(1);

    $shifts = R::table($props, 'shifts');
    expect(array_column($shifts['rows'], 'id'))->toBe(['SHIFT00000000000000000001', 'SHIFT00000000000000000002'])
        ->and($shifts['rows'][0])->toMatchArray(['closedAt' => '2026-09-23T16:00:00Z', 'till' => '01 – Till 1', 'shop' => 'Leeds', 'float' => '100.00',
            'cashExpected' => '100.00', 'cashDeclared' => '97.50', 'cashVariance' => '-2.50', 'variance' => '-1.50', 'z' => 41, 'warning' => true])
        ->and(R::table($props, 'tenders')['rows'])->toHaveCount(4);

    $z = R::props($this->actingAs($this->owner)->get('/app/reports/shifts?period=last7Days&view=z'));
    expect(R::table($z, 'z')['rows'])->toHaveCount(1)
        ->and(R::table($z, 'z')['rows'][0])->toMatchArray(['sequenceNo' => 41, 'variance' => '-1.50', 'warning' => true, 'periodEnd' => '2026-09-23T16:00:00Z']);

    // One shop: only Bradford's shift. The CSV shows London times.
    $manager = R::member($this->kirkgate, CompanyRole::Manager, TillFixtures::BRADFORD);
    $mine = R::props($this->actingAs($manager)->get('/app/reports/shifts?period=last7Days'));
    expect(R::figure($mine, 'shifts')['value'])->toBe(1)->and(R::figure($mine, 'cashVariance')['value'])->toBe('1.00');
    expect($this->actingAs($this->owner)->get('/app/reports/shifts/export?period=last7Days')->streamedContent())->toContain('2026-09-23 17:00');
});
