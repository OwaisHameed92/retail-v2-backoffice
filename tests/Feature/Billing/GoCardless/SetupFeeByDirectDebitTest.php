<?php

use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\SetupFeeMethod;
use App\Domain\Billing\GoCardless\Actions\CollectSetupFeeByDirectDebit;
use App\Domain\Billing\GoCardless\Actions\RetryDirectDebitPayment;
use App\Domain\Billing\GoCardless\Data\GcPayment;
use App\Domain\Billing\GoCardless\Enums\PaymentKind;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Mail\Mailables\DirectDebitFailedMail;
use App\Domain\Mail\Mailables\InvoiceMail;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

/*
 * Owner 2026-11-05: "Setup fee collected by Direct Debit", a per-business exception staff switch on in Payment
 * settings. The real case on the UK: S & S Store, setup fee £950 + VAT = £1,140 in 3 instalments of £380, monthly by
 * Direct Debit, no mandate yet, setup fee not invoiced yet.
 */
beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-11-05 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();
    $this->admin = $this->admin();
});

function ssStore(object $test, string $name = 'S & S Store', string $code = 'SNS'): Company
{
    return $test->directDebitTenant($name, tills: 1, code: $code, setupFee: '950.00', instalments: 3, method: SetupFeeMethod::Manual);
}

/** Payment settings → Setup fee: By Direct Debit (what staff click). */
function switchSetupFeeToDirectDebit(object $test, Company $company, bool $on = true): void
{
    $test->actingAs($test->admin, 'admin')->put(route('admin.billing.tenants.direct-debit.settings', $company), [
        'billing_mode' => 'directDebit', 'setup_fee_override' => '950.00', 'setup_fee_instalments' => 3, 'setup_fee_by_direct_debit' => $on,
    ])->assertSessionHasNoErrors();
}

/** @return list<Invoice> */
function setupInvoicesOf(Company $company): array
{
    return Invoice::withoutCompanyScope()->where('company_id', $company->id)->where('kind', InvoiceKind::SetupFee->value)
        ->orderBy('due_date')->get()->all();
}

/** The plain text of a queued email. */
function setupFeeDdMailText(InvoiceMail $mail): string
{
    return html_entity_decode(strip_tags((string) preg_replace('#<style.*?</style>#s', '', $mail->render())));
}

/** @return list<GcPayment> */
function setupFeePaymentsAtGoCardless(object $test): array
{
    return $test->gc->oneOffPayments();
}

test('S & S Store: three £380 instalments collected by Direct Debit on their due dates, the subscription after the first', function () {
    $company = ssStore($this);
    switchSetupFeeToDirectDebit($this, $company);

    expect($this->billingAccountOf($company)->setup_fee_by_direct_debit)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'billing.setup_fee_direct_debit_on')->where('company_id', $company->id)->exists())->toBeTrue();

    // Invoice setup fee: 3 invoices of £380.00, a month apart. No mandate yet: nothing is charged.
    $this->actingAs($this->admin, 'admin')->post(route('admin.billing.tenants.direct-debit.setup-fee', $company))->assertSessionHasNoErrors();
    $parts = setupInvoicesOf($company);

    expect(array_map(fn (Invoice $part) => $part->total, $parts))->toBe(['380.00', '380.00', '380.00'])
        ->and(array_map(fn (Invoice $part) => $part->due_date->format('Y-m-d'), $parts))->toBe(['2026-11-12', '2026-12-05', '2027-01-05'])
        ->and(setupFeePaymentsAtGoCardless($this))->toBe([])
        ->and($this->gc->calls)->not->toContain('createPayment');

    // The emails never say cash or bank transfer: the Direct Debit collects it once it is set up.
    Mail::assertQueued(InvoiceMail::class, 3);
    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->data->directDebit && $mail->data->directDebitOn === null
        && str_contains(setupFeeDdMailText($mail), 'This is collected by Direct Debit') && ! str_contains(setupFeeDdMailText($mail), 'bank transfer'));

    // The owner sets up the Direct Debit: the first instalment goes on the mandate for its due date.
    $this->atLondon('2026-11-06 09:00');
    $this->setUpMandate($company);

    $created = setupFeePaymentsAtGoCardless($this);
    expect($created)->toHaveCount(1)
        ->and($created[0]->amountPence)->toBe(38000)
        ->and($created[0]->chargeDate)->toBe('2026-11-12')
        ->and($created[0]->metadata)->toMatchArray(['invoice_id' => $parts[0]->id, 'kind' => 'setupFee', 'company_id' => $company->id])
        ->and($this->gc->subscriptions)->toBe([]); // the monthly Direct Debit waits for the first instalment

    $row = GoCardlessPayment::withoutCompanyScope()->where('gc_payment_id', $created[0]->id)->sole();
    expect($row->kind)->toBe(PaymentKind::SetupFee)->and($row->invoice_id)->toBe($parts[0]->id)
        ->and($row->instalment)->toBe(1)->and($row->instalments)->toBe(3);

    // Each open instalment is emailed again with its Direct Debit day.
    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->data->invoiceNumber === $parts[0]->number
        && $mail->data->directDebitOn?->format('Y-m-d') === '2026-11-12' && str_contains(setupFeeDdMailText($mail), 'by Direct Debit on'));
    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->data->invoiceNumber === $parts[1]->number
        && $mail->data->directDebitOn?->format('Y-m-d') === '2026-12-05');

    // Running it again (a repeated webhook, the daily run) makes nothing new.
    expect(app(CollectSetupFeeByDirectDebit::class)->handle($company))->toBe([])
        ->and($this->runBillingOn('2026-11-07')['invoicesCreated'])->toBe(0)
        ->and(setupFeePaymentsAtGoCardless($this))->toHaveCount(1);

    // GoCardless collects it: the instalment is paid and the subscription starts.
    $this->atLondon('2026-11-16 09:00');
    $this->paymentEvent($created[0], PaymentStatus::Confirmed, 'confirmed')->assertOk();

    $recorded = Payment::withoutCompanyScope()->findOrFail($row->refresh()->payment_id);
    $state = SetupFeeState::for($company, $this->billingAccountOf($company));
    expect($recorded->method)->toBe(PaymentMethod::DirectDebit)->and($recorded->gateway)->toBe('gocardless')->and($recorded->amount)->toBe('380.00')
        ->and($this->fresh($parts[0])->status)->toBe(InvoiceStatus::Paid)
        ->and($state->status)->toBe(SetupFeeState::PART_PAID)->and($state->paidParts)->toBe(1)
        ->and($this->gc->lastSubscription())->not->toBeNull();

    // The later instalments go on the mandate 10 days before they are due, for their due date; once only.
    expect($this->runBillingOn('2026-11-24'))->toBeArray()->and(setupFeePaymentsAtGoCardless($this))->toHaveCount(1);
    $this->runBillingOn('2026-11-25');
    $this->runBillingOn('2026-11-26');
    $this->runBillingOn('2026-12-26');
    $this->runBillingOn('2026-12-27');

    $all = setupFeePaymentsAtGoCardless($this);
    expect(array_map(fn (GcPayment $payment) => [$payment->amountPence, $payment->chargeDate, $payment->metadata['invoice_id']], $all))->toBe([
        [38000, '2026-11-12', $parts[0]->id],
        [38000, '2026-12-05', $parts[1]->id],
        [38000, '2027-01-05', $parts[2]->id],
    ]);

    // The admin Billing tab shows each instalment and where it stands.
    $this->actingAs($this->admin, 'admin')->get(route('admin.tenants.show', $company))->assertInertia(fn (Assert $page) => $page
        ->where('billing.directDebit.setupFee.byDirectDebit', true)
        ->where('billing.directDebit.setupFee.parts.0.state', 'paid')
        ->where('billing.directDebit.setupFee.parts.1.state', 'scheduled')
        ->where('billing.directDebit.setupFee.parts.1.chargeDate', '2026-12-05'));

    // The tenant page says it is collected by Direct Debit, in 3 instalments.
    $this->actingAs($this->ownerOf($company), 'web')->get('/app/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('upfront.byDirectDebit', true)->where('upfront.parts', 3)
        ->where('setupFee.method', 'directDebit')
        ->where('setupFee.parts.1.directDebit.label', 'Collected by Direct Debit on')
        ->where('setupFee.parts.1.directDebit.date', '2026-12-05'));
});

test('the billing status card says the unpaid setup fee is collected by Direct Debit, never cash or bank transfer', function () {
    $company = ssStore($this);
    switchSetupFeeToDirectDebit($this, $company);

    $this->actingAs($this->ownerOf($company), 'web')->get('/app/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('status.setupFee.text', '£1,140.00 — not paid yet (by Direct Debit, in 3 instalments)'));

    switchSetupFeeToDirectDebit($this, $company, false);
    $this->actingAs($this->ownerOf($company), 'web')->get('/app/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('status.setupFee.text', '£1,140.00 — not paid yet (cash, card or bank transfer, in 3 instalments)'));
});

test('a failed instalment is reversed and emailed, never collected twice, and staff can retry it', function () {
    $company = ssStore($this);
    $this->setUpMandate($company);
    switchSetupFeeToDirectDebit($this, $company);
    $this->actingAs($this->admin, 'admin')->post(route('admin.billing.tenants.direct-debit.setup-fee', $company))->assertSessionHasNoErrors();

    $payment = setupFeePaymentsAtGoCardless($this)[0];
    $invoice = setupInvoicesOf($company)[0];
    $this->paymentEvent($payment, PaymentStatus::Confirmed, 'confirmed')->assertOk();
    expect($this->fresh($invoice)->status)->toBe(InvoiceStatus::Paid);

    // A late failure: the recorded payment is reversed, the instalment is owed again, the owner is emailed.
    $this->atLondon('2026-11-20 09:00');
    $this->paymentEvent($payment, PaymentStatus::Failed, 'failed', ['description' => 'Insufficient funds'])->assertOk();

    $row = GoCardlessPayment::withoutCompanyScope()->where('gc_payment_id', $payment->id)->sole();
    expect(Payment::withoutCompanyScope()->findOrFail($row->payment_id)->reversed_at)->not->toBeNull()
        ->and($this->fresh($invoice)->status)->not->toBe(InvoiceStatus::Paid)
        ->and($this->fresh($invoice)->balance)->toBe('380.00');
    Mail::assertQueued(DirectDebitFailedMail::class, fn (DirectDebitFailedMail $mail) => $mail->data->amount === '380.00' && $mail->hasTo($this->ownerOf($company)->email));

    // The daily run never puts a second payment on the same instalment.
    $this->runBillingOn('2026-11-21');
    expect(collect(setupFeePaymentsAtGoCardless($this))->where('metadata.invoice_id', $invoice->id))->toHaveCount(1);

    // Staff retry it like any Direct Debit payment.
    $retried = app(RetryDirectDebitPayment::class)->handle($company, $row->id);
    expect($retried->status->isPending())->toBeTrue();
});

test('recording an instalment by hand while GoCardless is collecting it is refused', function () {
    $company = ssStore($this);
    $this->setUpMandate($company);
    switchSetupFeeToDirectDebit($this, $company);
    $this->actingAs($this->admin, 'admin')->post(route('admin.billing.tenants.direct-debit.setup-fee', $company))->assertSessionHasNoErrors();
    $first = setupInvoicesOf($company)[0];

    $this->actingAs($this->admin, 'admin')->post(route('admin.billing.tenants.upfront', $company), [
        'upfront_method' => 'cash', 'idempotency_key' => (string) Str::uuid(),
    ])->assertSessionHasErrors(['upfront_amount' => "{$first->number} is being collected by Direct Debit on 12 Nov 2026. Record it by hand only if that payment fails."]);

    expect(Payment::withoutCompanyScope()->where('company_id', $company->id)->exists())->toBeFalse();
});
