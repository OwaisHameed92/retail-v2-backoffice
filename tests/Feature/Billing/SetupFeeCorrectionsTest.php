<?php

use App\Domain\Billing\Actions\ApplyCredit;
use App\Domain\Billing\Actions\DeleteDraftInvoice;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\OnboardTenantBilling;
use App\Domain\Billing\Actions\RecordUpfrontPayment;
use App\Domain\Billing\Actions\ReversePayment;
use App\Domain\Billing\Data\PortalBilling;
use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\GoCardless\Actions\ChargeSetupFee;
use App\Domain\Billing\GoCardless\Data\DirectDebitData;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\SetupFeeInvoices;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Plans\Enums\SetupFeeMode;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\SetupOnlyTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Audit fixes on the setup fee (docs/audit/2026-10-11-billing.md):
 * - BILL-2: "void and re-issue" of an unpaid setup fee keeps it unpaid (no 10-year licence before it is paid);
 * - BILL-5: "Setup fee paid by … on …" is the payment that stands, not a reversed one;
 * - BILL-6: a setup fee finished by a credit note or by credit unlocks the tills at once, as a recorded payment does.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, SetupOnlyTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->setVat(true);
    config(['billing.suspend_after_days' => 7]);
});

/** Pakistan instance (manual collection, no VAT). */
function sfcPakistan(): void
{
    config(['country.code' => 'PK', 'billing.vat.enabled' => false]);
    app()->forgetInstance(Country::class);
}

/** A setup-only business on trial (ends 26 Oct 10:00 London), clock at 20 Oct 10:00, onboarded with nothing paid. */
function sfcSetupOnly(object $test, string $fee = '600.00', SetupFeeMode $mode = SetupFeeMode::PerTill, int $tills = 2): Company
{
    $test->atLondon('2026-10-20 10:00');
    $company = $test->trialTenant('Patel News', $tills, '2026-10-26 10:00', 'PTL');
    $test->standardPlan()->forceFill(['setup_fee' => $fee, 'price_monthly' => '0.00', 'price_yearly' => '0.00', 'billing_type' => PlanBillingType::SetupOnly, 'setup_fee_mode' => $mode])->save();
    app(OnboardTenantBilling::class)->handle($company);

    return $company->refresh();
}

/** Every live till of the business holds the 10-year setup-only licence. */
function sfcTenYears(object $test, Company $company): bool
{
    foreach ($test->licencesOf($company) as $licence) {
        if (! ($licence->expires_at?->greaterThan(CarbonImmutable::parse('2036-10-01')) ?? false)) {
            return false;
        }
    }

    return true;
}

// ---------------------------------------------------------------------------------------------------------------
// BILL-2
// ---------------------------------------------------------------------------------------------------------------

test('BILL-2: void and re-issue of an unpaid setup fee keeps it unpaid; billing:run gives nothing until the corrected invoice is paid', function () {
    $company = sfcSetupOnly($this);
    [$unpaid] = app(ChargeSetupFee::class)->handle($company);
    [, $draft] = $this->voidIt($unpaid, 'Wrong amount', redraft: true);

    $state = SetupFeeState::for($company, $this->billingAccountOf($company));
    expect($state->status)->toBe(SetupFeeState::UNPAID)
        ->and($state->total)->toBe($draft->total)
        ->and($state->isStarted())->toBeFalse();

    $this->runBillingOn('2026-10-21');
    foreach ($this->licencesOf($company) as $licence) {
        expect($licence->expires_at)->toBeNull();
    }

    $issued = app(IssueInvoice::class)->handle($draft);
    expect(SetupFeeState::for($company, $this->billingAccountOf($company))->status)->toBe(SetupFeeState::UNPAID);

    $this->pay($company, $issued->total, [$issued->id => $issued->total]);
    expect(SetupFeeState::for($company, $this->billingAccountOf($company))->status)->toBe(SetupFeeState::PAID)
        ->and(sfcTenYears($this, $company))->toBeTrue();
});

test('BILL-2: voiding the setup fee without a corrected draft still waives it, and deleting the corrected draft does too', function () {
    $company = sfcSetupOnly($this);
    [$unpaid] = app(ChargeSetupFee::class)->handle($company);
    [, $draft] = $this->voidIt($unpaid, 'Wrong amount', redraft: true);
    expect(SetupFeeState::for($company, $this->billingAccountOf($company))->status)->toBe(SetupFeeState::UNPAID);

    app(DeleteDraftInvoice::class)->handle($draft);
    expect(SetupFeeState::for($company, $this->billingAccountOf($company))->status)->toBe(SetupFeeState::NONE);

    $other = sfcSetupOnlyOther($this);
    [$invoice] = app(ChargeSetupFee::class)->handle($other);
    $this->voidIt($invoice, 'Waived: agreed with the owner');
    expect(SetupFeeState::for($other, $this->billingAccountOf($other))->status)->toBe(SetupFeeState::NONE);

    $this->runBillingOn('2026-10-21');
    expect(sfcTenYears($this, $other))->toBeTrue();
});

test('BILL-2: a business with a paid setup fee is never made unpaid by a corrected draft left over', function () {
    $company = sfcSetupOnly($this);
    [$wrong] = app(ChargeSetupFee::class)->handle($company);
    $this->voidIt($wrong, 'Wrong amount', redraft: true); // the corrected draft is left as it is

    // The admin raised and was paid a new setup fee invoice instead (as Istanbul's corrections were done).
    $account = $this->billingAccountOf($company);
    $paid = app(IssueInvoice::class)->handle(SetupFeeInvoices::businessDraft($company, $account, '1000.00', 'Setup fee · Patel News'));
    $this->pay($company, $paid->total, [$paid->id => $paid->total]);

    $state = SetupFeeState::for($company, $this->billingAccountOf($company));
    expect($state->status)->toBe(SetupFeeState::PAID)
        ->and($state->total)->toBe('1200.00')
        ->and(sfcTenYears($this, $company))->toBeTrue();
});

test('BILL-2 PK: void and re-issue of an unpaid setup fee keeps it unpaid on a manual-collection instance', function () {
    sfcPakistan();
    $company = sfcSetupOnly($this, '60000.00');
    [$unpaid] = app(ChargeSetupFee::class)->handle($company);
    [, $draft] = $this->voidIt($unpaid, 'Wrong amount', redraft: true);

    expect(SetupFeeState::for($company, $this->billingAccountOf($company))->status)->toBe(SetupFeeState::UNPAID);
    $this->runBillingOn('2026-10-21');
    expect($this->licencesOf($company)[0]->expires_at)->toBeNull();

    $issued = app(IssueInvoice::class)->handle($draft);
    $this->pay($company, $issued->total, [$issued->id => $issued->total], PaymentMethod::JazzCash);
    expect(sfcTenYears($this, $company))->toBeTrue();
})->group('country-pk');

// ---------------------------------------------------------------------------------------------------------------
// BILL-5
// ---------------------------------------------------------------------------------------------------------------

test('BILL-5: Istanbul’s portal and Payment settings show the cash payment of 8 Oct that stands, not the reversed bank transfer', function () {
    $istanbul = $this->istanbulLike();
    $company = $istanbul['company'];
    $portal = app(CurrentCompany::class)->runAs($company, fn () => PortalBilling::for($company))['upfront'];
    $admin = DirectDebitData::for($company)['upfront'];

    foreach ([$portal, $admin] as $upfront) {
        expect($upfront['status'])->toBe(SetupFeeState::PAID)
            ->and($upfront['total'])->toBe('£1,800.00')
            ->and($upfront['recorded'])->toBeTrue()
            ->and($upfront['method'])->toBe('Cash')
            ->and($upfront['amount'])->toBe('£1,800.00')
            ->and(BillingDates::localDate(CarbonImmutable::parse($upfront['recordedAt']))->format('Y-m-d'))->toBe('2026-10-08');
    }

    // The stamp itself is left as it was (display only).
    expect($this->billingAccountOf($company)->upfront_amount)->toBe('1440.00');
});

test('BILL-5: a setup fee whose only payment was reversed and its invoice voided shows nothing recorded; a £0 setup fee shows its record', function () {
    $company = sfcSetupOnly($this, '1200.00', SetupFeeMode::PerBusiness, 1);
    app(RecordUpfrontPayment::class)->handle($company, new UpfrontPayment(null, PaymentMethod::BankTransfer));
    app(ReversePayment::class)->handle(Payment::withoutCompanyScope()->where('company_id', $company->id)->sole(), 'Bounced');
    $this->voidIt($this->fresh(Invoice::withoutCompanyScope()->where('company_id', $company->id)->sole()), 'Waived after all');

    $upfront = DirectDebitData::for($company)['upfront'];
    expect($upfront['status'])->toBe(SetupFeeState::NONE)
        ->and($upfront['recorded'])->toBeFalse()
        ->and($upfront['amount'])->toBeNull()
        ->and($upfront['method'])->toBeNull();

    $free = sfcSetupOnlyOther($this);
    app(RecordUpfrontPayment::class)->handle($free, new UpfrontPayment('0', PaymentMethod::Cash));
    $upfront = DirectDebitData::for($free)['upfront'];
    expect($upfront['recorded'])->toBeTrue()
        ->and($upfront['amount'])->toBe('£0.00')
        ->and($upfront['method'])->toBe('Cash');
});

// ---------------------------------------------------------------------------------------------------------------
// BILL-6
// ---------------------------------------------------------------------------------------------------------------

test('BILL-6: a setup-only fee finished with a credit note gives the 10-year licence at once', function () {
    $company = sfcSetupOnly($this, '1200.00', SetupFeeMode::PerBusiness, 1);
    [$invoice] = app(ChargeSetupFee::class)->handle($company);

    $this->pay($company, '1000.00', [$invoice->id => '1000.00']);
    expect($this->licencesOf($company)[0]->expires_at)->toBeNull();
    $this->creditIt($this->fresh($invoice), '440.00', 'Agreed discount');

    expect($this->fresh($invoice)->status)->toBe(InvoiceStatus::Paid)
        ->and(SetupFeeState::for($company, $this->billingAccountOf($company))->status)->toBe(SetupFeeState::PAID)
        ->and(sfcTenYears($this, $company))->toBeTrue();

    $this->atLondon('2026-10-27 12:00'); // after the trial: still trading
    expect($this->tillSeesNow($this->licencesOf($company)[0])->status)->toBe(LicenceStatus::Active);
});

test('BILL-6: a setup fee finished with credit (Apply credit, or credit taken when it is issued) unlocks at once', function () {
    $company = sfcSetupOnly($this, '1200.00', SetupFeeMode::PerBusiness, 1);
    [$invoice] = app(ChargeSetupFee::class)->handle($company);
    $this->pay($company, '1440.00', []); // money on account, not put on the invoice
    expect($this->licencesOf($company)[0]->expires_at)->toBeNull();

    app(ApplyCredit::class)->handle($company);
    expect($this->fresh($invoice)->status)->toBe(InvoiceStatus::Paid)
        ->and(sfcTenYears($this, $company))->toBeTrue();

    $other = sfcSetupOnlyOther($this);
    $this->pay($other, '1440.00', []); // credit held before the setup fee is invoiced
    [$issued] = app(ChargeSetupFee::class)->handle($other);
    expect($this->fresh($issued)->status)->toBe(InvoiceStatus::Paid)
        ->and(sfcTenYears($this, $other))->toBeTrue();
});

test('BILL-6: a credit note that does not finish the setup fee unlocks nothing', function () {
    $company = sfcSetupOnly($this, '1200.00', SetupFeeMode::PerBusiness, 1);
    [$invoice] = app(ChargeSetupFee::class)->handle($company);
    $this->pay($company, '500.00', [$invoice->id => '500.00']);
    $this->creditIt($this->fresh($invoice), '100.00', 'Goodwill');

    expect($this->fresh($invoice)->status)->not->toBe(InvoiceStatus::Paid)
        ->and($this->licencesOf($company)[0]->expires_at)->toBeNull();
});

test('BILL-6 PK: a setup-only fee finished with a credit note gives the 10-year licence at once', function () {
    sfcPakistan();
    $company = sfcSetupOnly($this, '60000.00', SetupFeeMode::PerBusiness, 1);
    [$invoice] = app(ChargeSetupFee::class)->handle($company);
    $this->pay($company, '50000.00', [$invoice->id => '50000.00'], PaymentMethod::JazzCash);
    $this->creditIt($this->fresh($invoice), '10000.00', 'Agreed discount');

    expect($this->fresh($invoice)->status)->toBe(InvoiceStatus::Paid)
        ->and(sfcTenYears($this, $company))->toBeTrue();
})->group('country-pk');

/** A second setup-only business (£1,200 per business, 1 till) on the same plan, for the same test. */
function sfcSetupOnlyOther(object $test): Company
{
    $company = $test->trialTenant('Khan Mini Mart', 1, '2026-10-26 10:00', 'KMM');
    $test->standardPlan()->forceFill(['setup_fee' => '1200.00', 'price_monthly' => '0.00', 'price_yearly' => '0.00', 'billing_type' => PlanBillingType::SetupOnly, 'setup_fee_mode' => SetupFeeMode::PerBusiness])->save();
    app(OnboardTenantBilling::class)->handle($company);

    return $company->refresh();
}
