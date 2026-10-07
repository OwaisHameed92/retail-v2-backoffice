<?php

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\GoCardless\Support\FakeGoCardlessClient;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Mail\Mailables\InvoiceMail;
use App\Domain\Mail\Mailables\PlanChangedMail;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Shared\Country\Country;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\ChangePlanHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, ChangePlanHelpers::class);

/*
 * Change plan on a manual-collection instance (Pakistan, P5): no Direct Debit, no mandate deadline, GoCardless never
 * called; a recurring fee starts with the first period invoice issued and emailed at once, paid by hand.
 */
beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    config(['country.code' => 'PK', 'billing.vat.enabled' => false, 'billing.suspend_after_days' => 7]);
    app()->forgetInstance(Country::class);
    $this->gc = FakeGoCardlessClient::install();
    $this->atLondon('2026-10-24 10:00');
});

/** The mail as the customer reads it (text part). */
function planChangedText(PlanChangedMail $mail): string
{
    return html_entity_decode(strip_tags((string) $mail->render()));
}

test('setup only → setup + monthly: the first period invoice is issued and emailed now, paid by hand; tills stay valid', function () {
    $company = $this->cpOnboarded(PlanBillingType::SetupOnly, '50000.00', method: PaymentMethod::BankTransfer);
    $to = $this->cpPlan('Setup + monthly', PlanBillingType::SetupAndRecurring, '50000.00', '2500.00');

    $preview = $this->previewPlan($company, $to)->assertOk()->json();
    expect(json_encode($preview))->not->toContain('Direct Debit')->not->toContain('£')
        ->and($preview['invoices'][0]['when'])->toBe('Issued and emailed now, due 31 Oct 2026. Paid by hand.');

    $this->changePlan($company, $to)->assertRedirect()->assertSessionHasNoErrors();

    $period = $this->invoicesOfKind($company, InvoiceKind::Subscription);
    $account = $this->billingAccountOf($company);
    expect($period)->toHaveCount(1)
        ->and($period[0]->total)->toBe('5000.00')
        ->and($period[0]->status)->toBe(InvoiceStatus::Issued)
        ->and($period[0]->due_date->format('Y-m-d'))->toBe('2026-10-31')
        ->and($account->billing_mode)->toBe(BillingMode::UpfrontCash)
        ->and($account->mandate_deadline_at)->toBeNull()
        ->and($account->recurring_starts_on)->toBeNull()
        ->and($this->invoicesOfKind($company, InvoiceKind::SetupFee))->toHaveCount(1)
        ->and($this->gc->calls)->toBe([]);

    foreach ($this->licencesOf($company) as $licence) {
        expect($licence->expires_at->toDateTimeString())->toBe(BillingDates::endOfDay(BillingDates::date('2026-11-23'))->toDateTimeString());
    }

    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->data->invoiceNumber === $period[0]->number);
    Mail::assertQueued(PlanChangedMail::class, fn (PlanChangedMail $mail) => ! str_contains(planChangedText($mail), 'Direct Debit')
        && str_contains(planChangedText($mail), 'JazzCash'));

    // Paid by hand; the next period is invoiced by billing:run, 7 days before the tills run out.
    $this->pay($company, '5000.00', method: PaymentMethod::JazzCash);
    expect($period[0]->refresh()->status)->toBe(InvoiceStatus::Paid);
    $this->runBillingOn('2026-11-17');
    expect($this->invoicesOfKind($company, InvoiceKind::Subscription))->toHaveCount(2)
        ->and($this->invoicesOfKind($company, InvoiceKind::Subscription)[1]->period_start->format('Y-m-d'))->toBe('2026-11-24');
})->group('country-pk');

test('monthly only → setup + monthly: the setup fee invoice is issued and emailed; no Direct Debit anywhere', function () {
    $company = $this->payingTenant('Lahore Mart', 2, 'LHR');
    $this->standardPlan()->forceFill(['price_monthly' => '2500.00', 'price_yearly' => '25000.00', 'setup_fee' => '0.00', 'billing_type' => null])->save();
    $to = $this->cpPlan('Setup + monthly', PlanBillingType::SetupAndRecurring, '50000.00', '2500.00');

    $this->changePlan($company, $to)->assertRedirect()->assertSessionHasNoErrors();

    $setup = $this->invoicesOfKind($company, InvoiceKind::SetupFee);
    expect($setup)->toHaveCount(1)->and($setup[0]->total)->toBe('50000.00')
        ->and($this->invoicesOfKind($company, InvoiceKind::Subscription))->toBe([])
        ->and($this->gc->calls)->toBe([]);
    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->data->invoiceNumber === $setup[0]->number);
})->group('country-pk');

test('setup + monthly → setup only with the setup fee paid: the full licence at once', function () {
    $company = $this->cpOnboarded(PlanBillingType::SetupAndRecurring, '50000.00', '2500.00', method: PaymentMethod::Easypaisa);

    $this->changePlan($company, $this->cpPlan('Setup only', PlanBillingType::SetupOnly, '40000.00', '0.00'))->assertRedirect()->assertSessionHasNoErrors();

    foreach ($this->licencesOf($company) as $licence) {
        expect($licence->expires_at->setTimezone('Asia/Karachi')->format('Y-m-d'))->toBe('2036-10-24');
    }
    expect($this->gc->calls)->toBe([]);
})->group('country-pk');
