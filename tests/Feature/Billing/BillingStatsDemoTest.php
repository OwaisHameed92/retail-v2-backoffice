<?php

use App\Domain\Admin\Queries\Dashboard\DashboardRows;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\GoCardless\Support\DirectDebitStats;
use App\Domain\Billing\Queries\BillingStats;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Audit BILL-4 (docs/audit/2026-10-11-billing.md): the billing overview, its Direct Debit figures and the admin
 * dashboard's money count real businesses only. Demo businesses (demo:billing, `is_demo`) run on the live server with
 * paid and failed invoices; their money is left out of every total.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->setVat(true);
    $this->atLondon('2026-10-01 10:00');
});

/** A demo business with a £30 invoice, £10 paid on it, and a draft. */
function statsDemoBusiness(object $test): Company
{
    $demo = $test->payingTenant('DEMO – Setup + monthly', 1, 'DEM');
    $demo->forceFill(['is_demo' => true])->save();
    $invoice = $test->issuedFor($demo); // £30.00 with VAT
    expect($invoice->number)->toStartWith('DEMO-INV-');
    $test->pay($demo, '10.00');

    return $demo;
}

test('BillingStats counts no demo money: cash due, collected, drafts, credit and suspensions', function () {
    statsDemoBusiness($this);

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

test('real businesses are still counted next to a demo one', function () {
    statsDemoBusiness($this);
    $real = $this->payingTenant('Khan Mini Mart', 1, 'KMM');
    $this->issuedFor($real);
    $this->pay($real, '50.00'); // £30 invoice paid, £20 credit

    $stats = BillingStats::for(CarbonImmutable::now());

    expect($stats['cashDue'])->toBe(['count' => 0, 'amount' => '0.00'])
        ->and($stats['collectedThisMonth'])->toBe(['count' => 1, 'amount' => '50.00'])
        ->and($stats['creditHeld'])->toBe('20.00');
});

test('the Direct Debit figures and the admin dashboard money leave demo businesses out', function () {
    $demo = statsDemoBusiness($this);
    $this->billingAccountOf($demo)->forceFill(['billing_mode' => BillingMode::DirectDebit, 'gc_mandate_id' => 'DEMO-MD1', 'gc_mandate_status' => 'active'])->save();
    $row = new GoCardlessPayment(['gc_payment_id' => 'DEMO-PM1', 'kind' => 'subscription', 'amount' => '30.00', 'status' => PaymentStatus::PendingSubmission, 'charge_date' => '2026-10-05']);
    $row->company_id = $demo->id;
    $row->save();
    $this->pay($demo, '20.00'); // the rest: the demo invoice is paid

    $dd = DirectDebitStats::for(CarbonImmutable::now(), true);
    $rows = DashboardRows::load(CarbonImmutable::now());

    expect($dd['activeMandates'])->toBe(0)
        ->and($dd['upcoming']['count'])->toBe(0)
        ->and($rows->paid)->toHaveCount(0)
        ->and($rows->overdue)->toHaveCount(0);
});

test('the billing overview renders the real figures', function () {
    statsDemoBusiness($this);

    $this->actingAs($this->admin(), 'admin')->get(route('admin.billing.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('stats.cashDue.count', 0)->where('stats.collectedThisMonth.count', 0));
});
