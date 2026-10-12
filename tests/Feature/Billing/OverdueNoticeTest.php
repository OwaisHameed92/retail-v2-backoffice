<?php

use App\Domain\Billing\Actions\ReversePayment;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Audit UI-5 (docs/audit/2026-10-11-ui.md): an overdue business sees a warning on every portal page before its tills
 * lock: the amount overdue, the day billing:run suspends it, a link to Billing for users with billing permission and
 * "ask the owner" for the others. The grace and suspension rules are unchanged: the date shown is the day it happens.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    // The live value (owner 2026-10-05); the suite pins 14 for older tests.
    config(['billing.suspend_after_days' => 7]);
});

/** A UK business paying by hand, its £30 invoice issued 24 Oct (due 31 Oct) and marked overdue on 1 Nov. */
function overdueNoticeBusiness(object $test): array
{
    $test->setVat(true);
    $test->atLondon('2026-10-24 10:00');
    $company = $test->payingTenant('Khan Mini Mart', 1, 'KMM');
    $invoice = $test->issuedFor($company);
    expect($invoice->due_date->format('Y-m-d'))->toBe('2026-10-31');
    $test->runBillingOn('2026-11-01');
    expect($test->companyFresh($company)->status)->toBe(CompanyStatus::Overdue);

    return [$company, $test->fresh($invoice)];
}

test('an overdue business sees a warning in the portal before its tills lock', function () {
    [$company] = overdueNoticeBusiness($this);
    $this->atLondon('2026-11-03 09:00');

    $this->actingAs($this->ownerOf($company), 'web')->get('/app')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('billingNotice.kind', 'overdue')
            ->where('billingNotice.amountDue', '30.00')
            ->where('billingNotice.invoices', 1)
            ->where('billingNotice.lockOn', '2026-11-08')
            ->where('billingNotice.daysLeft', 5)
            ->where('billingNotice.canPay', true)
            ->where('billingNotice.url', route('app.billing')));

    // Staff without billing permission get the same warning, without the link (the banner asks them to tell the owner).
    $staff = $this->addMember($company, CompanyRole::Staff);
    $this->actingAs($staff, 'web')->get('/app')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('billingNotice.kind', 'overdue')->where('billingNotice.canPay', false));
});

test('the lock date shown is the day billing:run suspends: not before, and the rules are unchanged', function () {
    [$company] = overdueNoticeBusiness($this);

    $this->runBillingOn('2026-11-07');
    expect($this->companyFresh($company)->status)->toBe(CompanyStatus::Overdue);
    $this->actingAs($this->ownerOf($company), 'web')->get('/app')
        ->assertInertia(fn (Assert $page) => $page->where('billingNotice.lockOn', '2026-11-08')->where('billingNotice.daysLeft', 1));

    $this->runBillingOn('2026-11-08');
    expect($this->companyFresh($company)->status)->toBe(CompanyStatus::Suspended);
});

test('paid: the warning goes', function () {
    [$company, $invoice] = overdueNoticeBusiness($this);
    $this->atLondon('2026-11-03 09:00');
    $this->pay($company, $invoice->balance, [$invoice->id => $invoice->balance]);

    $this->actingAs($this->ownerOf($company), 'web')->get('/app')
        ->assertInertia(fn (Assert $page) => $page->where('billingNotice', null));
});

test('a business with nothing overdue, or another business’s overdue invoice, shows no warning', function () {
    [$overdue] = overdueNoticeBusiness($this);
    $fine = $this->payingTenant('Patel News', 1, 'PTL', paidUntil: '2026-12-31');

    $this->actingAs($this->ownerOf($fine), 'web')->get('/app')
        ->assertInertia(fn (Assert $page) => $page->where('billingNotice', null));
    expect(Company::query()->findOrFail($overdue->id)->status)->toBe(CompanyStatus::Overdue);
});

test('a chargeback reopening an invoice: the lock date counts from the reversal, as the suspension does', function () {
    $this->setVat(true);
    $this->atLondon('2026-10-24 10:00');
    $company = $this->payingTenant('Leeds News', 1, 'LDN');
    $invoice = $this->issuedFor($company);
    $payment = $this->pay($company, $invoice->total, [$invoice->id => $invoice->total], PaymentMethod::BankTransfer)->payment;

    $this->atLondon('2026-11-20 10:00');
    app(ReversePayment::class)->handle($payment, 'Charged back');
    $this->runBillingOn('2026-11-21');
    expect($this->fresh($invoice)->status)->toBe(InvoiceStatus::Overdue);

    $this->actingAs($this->ownerOf($company), 'web')->get('/app')
        ->assertInertia(fn (Assert $page) => $page->where('billingNotice.kind', 'overdue')->where('billingNotice.lockOn', '2026-11-28'));

    $this->runBillingOn('2026-11-27');
    expect($this->companyFresh($company)->status)->toBe(CompanyStatus::Overdue);
    $this->runBillingOn('2026-11-28');
    expect($this->companyFresh($company)->status)->toBe(CompanyStatus::Suspended);
});

test('the Direct Debit set-up notice keeps its place and shape (now with its kind)', function () {
    [$company] = overdueNoticeBusiness($this);
    $this->billingAccountOf($company)->forceFill(['billing_mode' => BillingMode::DirectDebit, 'mandate_deadline_at' => now()->addDays(2)])->save();

    $this->actingAs($this->ownerOf($company), 'web')->get('/app')
        ->assertInertia(fn (Assert $page) => $page->where('billingNotice.kind', 'directDebit')->where('billingNotice.canSetUp', true)->has('billingNotice.daysLeft'));
});

test('PK: an overdue business sees the warning with the amount in rupees to format', function () {
    config(['country.code' => 'PK', 'billing.vat.enabled' => false]);
    app()->forgetInstance(Country::class);
    $this->travelTo(now()->parse('2026-10-24 10:00', 'Asia/Karachi'));
    $company = $this->payingTenant('Lahore Mart', 1, 'LHR');
    $this->standardPlan()->forceFill(['price_monthly' => '2500.00', 'price_yearly' => '25000.00'])->save();
    $invoice = $this->issuedFor($company);
    $this->travelTo(now()->parse($invoice->due_date->addDay()->format('Y-m-d').' 06:00', 'Asia/Karachi'));
    $this->runBilling();
    expect(Invoice::withoutCompanyScope()->findOrFail($invoice->id)->status)->toBe(InvoiceStatus::Overdue);

    $this->actingAs($this->ownerOf($company), 'web')->get('/app')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('country.code', 'PK')
            ->where('billingNotice.kind', 'overdue')
            ->where('billingNotice.amountDue', '2500.00')
            ->where('billingNotice.lockOn', $invoice->due_date->addDays(8)->format('Y-m-d'))
            ->where('billingNotice.canPay', true));
})->group('country-pk');
