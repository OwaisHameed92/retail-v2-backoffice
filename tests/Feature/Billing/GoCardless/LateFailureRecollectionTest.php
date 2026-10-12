<?php

use App\Domain\Billing\Actions\RunBilling;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\GoCardless\Actions\ReconcileGoCardless;
use App\Domain\Billing\GoCardless\Data\GcPayment;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Mail\Mailables\DirectDebitFailedMail;
use App\Domain\Tenancy\Enums\CompanyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Audit BILL-3 (docs/audit/2026-10-11-billing.md): a Direct Debit payment that fails after it was confirmed (a late
 * failure), is retried and collected, is recorded again; a cancelled chargeback likewise. Never twice for the same
 * GoCardless payment, whatever the order or number of deliveries.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->setVat(true);
    // The live value (owner 2026-10-05); the suite pins 14 for older tests.
    config(['billing.suspend_after_days' => 7]);
    $this->atLondon('2026-10-24 10:00');
    $this->fakeGoCardless();

    $this->company = $this->directDebitTenant(tills: 2);
    $this->setUpMandate($this->company);
    $this->subscription = $this->gc->lastSubscription();
});

/** The 1 Nov payment: created, confirmed on 4 Nov, then failed late (the bank took it back). */
function recollectLateFailure(object $test): GcPayment
{
    $payment = $test->gc->collect($test->subscription->id, '2026-11-01');
    $test->paymentEvent($payment, PaymentStatus::PendingSubmission, 'created')->assertOk();
    $test->atLondon('2026-11-04 09:00');
    $test->paymentEvent($payment, PaymentStatus::Confirmed, 'confirmed')->assertOk();
    $test->paymentEvent($payment, PaymentStatus::Failed, 'late_failure_settled')->assertOk();

    return $payment;
}

function recollectRow(GcPayment $payment): GoCardlessPayment
{
    return GoCardlessPayment::withoutCompanyScope()->where('gc_payment_id', $payment->id)->sole();
}

test('confirmed → failed late → retried → confirmed records the payment again: the invoice is paid and the business is never suspended', function () {
    $payment = recollectLateFailure($this);
    $first = Payment::withoutCompanyScope()->where('company_id', $this->company->id)->sole();
    expect($first->reversed_at)->not->toBeNull();

    $this->paymentEvent($payment, PaymentStatus::PendingSubmission, 'resubmission_requested')->assertOk();
    $this->atLondon('2026-11-10 09:00');
    $this->paymentEvent($payment, PaymentStatus::Confirmed, 'confirmed')->assertOk();

    $row = recollectRow($payment);
    $standing = Payment::withoutCompanyScope()->where('company_id', $this->company->id)->whereNull('reversed_at')->sole();
    $invoice = Invoice::withoutCompanyScope()->findOrFail($row->invoice_id);

    expect($row->payment_id)->toBe($standing->id)
        ->and($standing->id)->not->toBe($first->id)
        ->and($standing->amount)->toBe('60.00')
        ->and($standing->gateway_reference)->toBe($payment->id)
        ->and(Payment::withoutCompanyScope()->findOrFail($first->id)->reversed_at)->not->toBeNull()
        ->and($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and($invoice->balance)->toBe('0.00');

    // Renewed to the period end, and billing:run past the old grace leaves it alone.
    foreach ($this->licencesOf($this->company) as $licence) {
        expect($licence->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-11-30'));
    }
    $this->runBillingOn('2026-11-11');
    $this->runBillingOn('2026-11-19');
    expect($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Active);
});

test('a re-delivered or repeated collected status after the re-collection records nothing more', function () {
    $payment = recollectLateFailure($this);
    $this->paymentEvent($payment, PaymentStatus::PendingSubmission, 'resubmission_requested')->assertOk();
    $this->paymentEvent($payment, PaymentStatus::Confirmed, 'confirmed')->assertOk();
    $this->paymentEvent($payment, PaymentStatus::Confirmed, 'confirmed')->assertOk();
    $this->paymentEvent($payment, PaymentStatus::PaidOut, 'paid_out')->assertOk();

    // The daily reconcile replays the same path: still one payment standing.
    app(ReconcileGoCardless::class)->handle(CarbonImmutable::now());

    expect(Payment::withoutCompanyScope()->where('company_id', $this->company->id)->count())->toBe(2)
        ->and(Payment::withoutCompanyScope()->where('company_id', $this->company->id)->whereNull('reversed_at')->count())->toBe(1);
});

test('a confirmed payment delivered twice without a failure is still recorded once', function () {
    $payment = $this->gc->collect($this->subscription->id, '2026-11-01');
    $this->paymentEvent($payment, PaymentStatus::PendingSubmission, 'created')->assertOk();
    $this->paymentEvent($payment, PaymentStatus::Confirmed, 'confirmed')->assertOk();
    $this->paymentEvent($payment, PaymentStatus::Confirmed, 'confirmed')->assertOk();
    $this->paymentEvent($payment, PaymentStatus::PaidOut, 'paid_out')->assertOk();
    app(ReconcileGoCardless::class)->handle(CarbonImmutable::now());

    expect(Payment::withoutCompanyScope()->where('company_id', $this->company->id)->count())->toBe(1);
});

test('the reconcile records a re-collection whose webhook was missed', function () {
    $payment = recollectLateFailure($this);

    // GoCardless retried and collected it; neither webhook arrived.
    $this->gc->setPaymentStatus($payment->id, PaymentStatus::Confirmed);
    $this->atLondon('2026-11-10 05:30');
    app(ReconcileGoCardless::class)->handle(CarbonImmutable::now());

    $row = recollectRow($payment);
    expect($row->status)->toBe(PaymentStatus::Confirmed)
        ->and(Invoice::withoutCompanyScope()->findOrFail($row->invoice_id)->status)->toBe(InvoiceStatus::Paid)
        ->and(Payment::withoutCompanyScope()->where('company_id', $this->company->id)->whereNull('reversed_at')->count())->toBe(1);
});

test('the retry failing again reverses nothing new and emails the failure again; a later collection is then recorded once', function () {
    $payment = recollectLateFailure($this);
    Mail::assertQueued(DirectDebitFailedMail::class);
    $failedEmails = count(Mail::queued(DirectDebitFailedMail::class));

    $this->paymentEvent($payment, PaymentStatus::PendingSubmission, 'resubmission_requested')->assertOk();
    $this->paymentEvent($payment, PaymentStatus::Failed, 'failed')->assertOk();
    expect(count(Mail::queued(DirectDebitFailedMail::class)))->toBeGreaterThan($failedEmails)
        ->and(Payment::withoutCompanyScope()->where('company_id', $this->company->id)->whereNull('reversed_at')->count())->toBe(0);

    $this->paymentEvent($payment, PaymentStatus::PendingSubmission, 'resubmission_requested')->assertOk();
    $this->paymentEvent($payment, PaymentStatus::Confirmed, 'confirmed')->assertOk();

    expect(Payment::withoutCompanyScope()->where('company_id', $this->company->id)->count())->toBe(2)
        ->and(Invoice::withoutCompanyScope()->findOrFail(recollectRow($payment)->invoice_id)->status)->toBe(InvoiceStatus::Paid);
});

test('a re-collected payment that is then charged back is reversed like any other', function () {
    $payment = recollectLateFailure($this);
    $this->paymentEvent($payment, PaymentStatus::PendingSubmission, 'resubmission_requested')->assertOk();
    $this->paymentEvent($payment, PaymentStatus::Confirmed, 'confirmed')->assertOk();
    $this->paymentEvent($payment, PaymentStatus::ChargedBack, 'charged_back')->assertOk();

    $row = recollectRow($payment);
    expect(Payment::withoutCompanyScope()->where('company_id', $this->company->id)->whereNull('reversed_at')->count())->toBe(0)
        ->and(Invoice::withoutCompanyScope()->findOrFail($row->invoice_id)->isOpen())->toBeTrue();
});

test('a cancelled chargeback (charged back → paid out) records the money again', function () {
    $payment = $this->gc->collect($this->subscription->id, '2026-11-01');
    $this->paymentEvent($payment, PaymentStatus::PendingSubmission, 'created')->assertOk();
    $this->paymentEvent($payment, PaymentStatus::Confirmed, 'confirmed')->assertOk();
    $this->paymentEvent($payment, PaymentStatus::ChargedBack, 'charged_back')->assertOk();
    $this->paymentEvent($payment, PaymentStatus::PaidOut, 'chargeback_cancelled')->assertOk();

    $row = recollectRow($payment);
    expect(Payment::withoutCompanyScope()->where('company_id', $this->company->id)->count())->toBe(2)
        ->and(Payment::withoutCompanyScope()->where('company_id', $this->company->id)->whereNull('reversed_at')->sole()->id)->toBe($row->payment_id)
        ->and(Invoice::withoutCompanyScope()->findOrFail($row->invoice_id)->status)->toBe(InvoiceStatus::Paid);
});

test('billing:run alone never records a Direct Debit payment (only GoCardless events do)', function () {
    $payment = recollectLateFailure($this);
    app(RunBilling::class)->handle(CarbonImmutable::now());

    expect(Payment::withoutCompanyScope()->where('company_id', $this->company->id)->whereNull('reversed_at')->count())->toBe(0)
        ->and(recollectRow($payment)->status)->toBe(PaymentStatus::Failed);
});
