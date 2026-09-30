<?php

use App\Domain\Cash\Data\CashFilters;
use App\Domain\Cash\Support\ZTotals;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/*
 * Module 5.4: the filters are read leniently (bad values dropped, dates swapped and capped) and a Z report's
 * totals JSON is read as sent, never trusted to be well formed.
 */

test('filters drop bad values, swap and cap the dates and read the alert amount', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 18:00', 'Europe/London'));
    $tenancy = app(CurrentCompany::class);

    $default = CashFilters::fromRequest(Request::create('/app/cash'), $tenancy, null);
    expect([$default->from, $default->to, $default->shop, $default->threshold])->toBe(['2026-09-17', '2026-09-23', null, null]);

    $odd = CashFilters::fromRequest(Request::create('/app/cash', 'GET', ['from' => '2026-09-30', 'to' => '2026-02-30', 'shop' => 'x', 'status' => 'lost', 'threshold' => '-5', 'till' => '<script>']), $tenancy, 'SHOP');
    expect([$odd->from, $odd->to, $odd->shop, $odd->status, $odd->threshold, $odd->till])->toBe(['2026-09-23', '2026-09-30', 'SHOP', null, null, null]);

    $long = CashFilters::fromRequest(Request::create('/app/cash', 'GET', ['from' => '2026-01-01', 'to' => '2026-09-23', 'threshold' => '7.5', 'status' => 'open']), $tenancy, null);
    expect([$long->from, $long->to, $long->threshold, $long->status])->toBe(['2026-06-24', '2026-09-23', '7.50', 'open']);
});

test('a Z report\'s totals are read as the till wrote them, and garbage reads as unreadable', function () {
    $z = ZTotals::parse('{"Tenders":[{"PaymentTypeName":"Cash","Expected":10,"Declared":9.5,"Variance":-0.5,"ExceedsThreshold":false},"junk"],"VarianceTotal":-0.5,"HasVarianceWarning":false}');
    expect($z->readable)->toBeTrue()
        ->and($z->tenders)->toHaveCount(1)
        ->and($z->tenders[0])->toMatchArray(['name' => 'Cash', 'expected' => '10.00', 'declared' => '9.50', 'variance' => '-0.50', 'terminal' => null])
        ->and([$z->variance, $z->warning, $z->alertOver])->toBe(['-0.50', false, null]);

    expect(ZTotals::parse('not json')->readable)->toBeFalse()
        ->and(ZTotals::parse(null)->tenders)->toBe([]);
});
