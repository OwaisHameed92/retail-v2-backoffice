<?php

use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Queries\BillingStats;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

beforeEach(function () {
    Mail::fake();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
});

test('with nothing billed every number is zero', function () {
    expect(BillingStats::for(CarbonImmutable::now()))->toBe([
        'cashDue' => ['count' => 0, 'amount' => '0.00'],
        'overdue' => ['count' => 0, 'amount' => '0.00'],
        'dueThisWeek' => ['count' => 0, 'amount' => '0.00'],
        'collectedThisMonth' => ['count' => 0, 'amount' => '0.00'],
        'drafts' => ['count' => 0, 'amount' => '0.00'],
        'creditHeld' => '0.00',
        'suspendedForBilling' => 0,
    ]);
});

test('cash due, overdue, due this week, drafts and credit across all tenants', function () {
    // Bravo: £30.00 issued on 12 Oct, due 19 Oct: overdue by today (not yet 14 days).
    $this->atLondon('2026-10-12 10:00');
    $bravo = $this->payingTenant('Bravo Mart', 1, 'BRV');
    $this->issuedFor($bravo);

    $this->atLondon('2026-10-24 10:00');

    // Alpha: £90.00 due in 3 days, £40.00 paid.
    $alpha = $this->payingTenant('Alpha Stores', 3, 'ALP');
    $account = $this->billingAccountOf($alpha);
    $account->payment_terms_days = 3;
    $account->save();
    $this->issuedFor($alpha);
    $this->pay($alpha, '40.00');

    // Echo: £60.00 due in 30 days (not this week), plus a paid and a void invoice that do not count.
    $echo = $this->payingTenant('Echo Ltd', 2, 'ECH');
    $account = $this->billingAccountOf($echo);
    $account->payment_terms_days = 30;
    $account->save();
    $this->issuedFor($echo);
    $this->voidIt($this->issuedFor($echo, new NewInvoice(allowOverlap: true)));
    $paid = $this->issuedFor($echo, new NewInvoice(allowOverlap: true));
    $this->pay($echo, '60.00', [$paid->id => '60.00']);

    // Charlie: a £60.00 draft. Delta: £25.50 held as credit.
    $this->draftFor($this->payingTenant('Charlie Co', 2, 'CHL'));
    $this->pay($this->payingTenant('Delta Deli', 1, 'DLT'), '25.50');

    $this->runBillingOn('2026-10-24'); // marks Bravo overdue (and drafts nothing new: all periods are covered)
    $this->atLondon('2026-10-24 10:00');

    $stats = BillingStats::for(CarbonImmutable::now());

    expect($stats['cashDue'])->toBe(['count' => 3, 'amount' => '140.00'])
        ->and($stats['overdue'])->toBe(['count' => 1, 'amount' => '30.00'])
        ->and($stats['dueThisWeek'])->toBe(['count' => 1, 'amount' => '50.00'])
        ->and($stats['drafts'])->toBe(['count' => 1, 'amount' => '60.00'])
        ->and($stats['creditHeld'])->toBe('25.50')
        ->and($stats['collectedThisMonth'])->toBe(['count' => 3, 'amount' => '125.50'])
        ->and($stats['suspendedForBilling'])->toBe(0);
});

test('due this week runs from today to six days ahead, London dates', function () {
    $company = $this->payingTenant(tills: 1);
    $account = $this->billingAccountOf($company);

    foreach ([0, 6, 7] as $terms) {
        $account->payment_terms_days = $terms;
        $account->save();
        $this->issuedFor($company, new NewInvoice(allowOverlap: true));
    }

    expect(BillingStats::for(CarbonImmutable::now())['dueThisWeek'])->toBe(['count' => 2, 'amount' => '60.00']);
});

test('collected this month starts at midnight London on the 1st', function () {
    $company = $this->payingTenant(tills: 1);

    $this->payAt($company, '7.00', '2026-09-30 23:30'); // 22:30 UTC: September
    $this->payAt($company, '12.34', '2026-10-01 00:30'); // still 30 Sept in UTC, but October in London
    $this->payAt($company, '0.10', '2026-10-24 09:00');
    $this->payAt($company, '0.20', '2026-10-24 09:59');

    expect(BillingStats::for(CarbonImmutable::now())['collectedThisMonth'])->toBe(['count' => 3, 'amount' => '12.64'])
        ->and(BillingStats::for(CarbonImmutable::now())['creditHeld'])->toBe('19.64');
});

test('collected this month in winter time, and never counts payments dated after now', function () {
    $company = $this->payingTenant(tills: 1);
    $this->atLondon('2026-12-01 00:30');

    $this->payAt($company, '5.00', '2026-11-30 23:59');
    $this->payAt($company, '6.00', '2026-12-01 00:10');
    $this->payAt($company, '99.00', '2026-12-01 09:00'); // later today

    expect(BillingStats::for(CarbonImmutable::now())['collectedThisMonth'])->toBe(['count' => 1, 'amount' => '6.00']);
});

test('billing suspensions are counted', function () {
    $this->atLondon('2026-10-01 10:00');
    $this->issuedFor($this->payingTenant('Late Ltd', 1, 'LTE'));

    $this->runBillingOn('2026-10-24');

    expect(BillingStats::for(CarbonImmutable::now())['suspendedForBilling'])->toBe(1)
        ->and(BillingStats::for(CarbonImmutable::now())['overdue'])->toBe(['count' => 1, 'amount' => '30.00']);
});

test('sums are exact over many small amounts', function () {
    $company = $this->payingTenant(tills: 1);

    foreach (range(1, 30) as $i) {
        $this->payAt($company, '0.10', '2026-10-24 08:00');
    }

    expect(BillingStats::for(CarbonImmutable::now())['collectedThisMonth'])->toBe(['count' => 30, 'amount' => '3.00'])
        ->and(BillingStats::for(CarbonImmutable::now())['creditHeld'])->toBe('3.00');
});
