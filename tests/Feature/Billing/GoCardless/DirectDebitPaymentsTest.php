<?php

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\GoCardless\Data\GcPayment;
use App\Domain\Billing\GoCardless\Enums\MandateStatus;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Enums\SubscriptionStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Mail\Mailables\DirectDebitCancelledMail;
use App\Domain\Mail\Mailables\DirectDebitFailedMail;
use App\Domain\Mail\Mailables\DirectDebitSetupMail;
use App\Domain\Mail\Mailables\InvoiceMail;
use App\Domain\Tenancy\Enums\CompanyStatus;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();

    $this->company = $this->directDebitTenant(tills: 2);
    $this->setUpMandate($this->company);
    $this->subscription = $this->gc->lastSubscription();
});

/** GoCardless creates the 1 Nov payment of the subscription and tells us. */
function createdPayment(object $test): GcPayment
{
    $payment = $test->gc->collect($test->subscription->id, '2026-11-01');
    $test->paymentEvent($payment, PaymentStatus::PendingSubmission, 'created')->assertOk();

    return $payment;
}

test('a new subscription payment gets its invoice for the next period, due on the collection date', function () {
    $payment = createdPayment($this);
    $row = GoCardlessPayment::withoutCompanyScope()->where('gc_payment_id', $payment->id)->sole();
    $invoice = Invoice::withoutCompanyScope()->findOrFail($row->invoice_id);

    expect($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->total)->toBe('60.00')
        ->and($invoice->period_start->format('Y-m-d'))->toBe('2026-11-01')
        ->and($invoice->period_end->format('Y-m-d'))->toBe('2026-11-30')
        ->and($invoice->due_date->format('Y-m-d'))->toBe('2026-11-01');
    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->data->invoiceNumber === $invoice->number && $mail->data->directDebitOn?->format('Y-m-d') === '2026-11-01');
});

test('a confirmed payment pays the invoice and renews the licences to the period end', function () {
    $payment = createdPayment($this);
    $this->atLondon('2026-11-04 09:00');

    $this->paymentEvent($payment, PaymentStatus::Confirmed, 'confirmed')->assertOk();

    $row = GoCardlessPayment::withoutCompanyScope()->where('gc_payment_id', $payment->id)->sole();
    $recorded = Payment::withoutCompanyScope()->findOrFail($row->payment_id);

    expect($row->status)->toBe(PaymentStatus::Confirmed)
        ->and($recorded->method)->toBe(PaymentMethod::DirectDebit)
        ->and($recorded->gateway)->toBe('gocardless')
        ->and($recorded->gateway_reference)->toBe($payment->id)
        ->and($recorded->amount)->toBe('60.00')
        ->and($this->fresh($row->invoice)->status)->toBe(InvoiceStatus::Paid);

    foreach ($this->licencesOf($this->company) as $licence) {
        expect($this->licenceFresh($licence)->expires_at->utc()->toDateTimeString())->toBe($this->londonEnd('2026-11-30'));
    }

    // paid_out afterwards (and a re-delivery) records nothing more.
    $this->paymentEvent($payment, PaymentStatus::PaidOut, 'paid_out')->assertOk();
    expect(Payment::withoutCompanyScope()->count())->toBe(1);
});

test('billing:run does not invoice a business GoCardless collects from', function () {
    expect($this->runBillingOn('2026-10-26')['invoicesCreated'])->toBe(0)
        ->and(Invoice::withoutCompanyScope()->count())->toBe(0);
});

test('a failed payment sends the dunning email, leaves the invoice unpaid and ends in suspension', function () {
    $payment = createdPayment($this);
    $this->atLondon('2026-11-04 09:00');

    $this->paymentEvent($payment, PaymentStatus::Failed, 'failed', ['description' => 'The bank account had insufficient funds.'])->assertOk();

    $row = GoCardlessPayment::withoutCompanyScope()->where('gc_payment_id', $payment->id)->sole();
    expect($row->failure_reason)->toBe('The bank account had insufficient funds.')
        ->and($this->fresh($row->invoice)->status)->toBe(InvoiceStatus::Issued);
    Mail::assertQueued(DirectDebitFailedMail::class, fn (DirectDebitFailedMail $mail) => ! $mail->data->reminder && $mail->data->amount === '60.00' && $mail->hasTo($this->ownerOf($this->company)->email));

    // Staff get a copy (owner rule 2026-10-05). The same event again: no second email.
    Mail::assertQueued(DirectDebitFailedMail::class, fn (DirectDebitFailedMail $mail) => $mail->hasTo(config('sspos.staff_email')));
    $this->paymentEvent($payment, PaymentStatus::Failed, 'failed')->assertOk();
    Mail::assertQueued(DirectDebitFailedMail::class, 2);

    // Overdue the next morning, a reminder after 5 days, suspended 14 days after the due date.
    expect($this->runBillingOn('2026-11-05')['invoicesOverdue'])->toBe(1)
        ->and($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Overdue);
    expect($this->runBillingOn('2026-11-10')['directDebitReminders'])->toBe(1);
    Mail::assertQueued(DirectDebitFailedMail::class, fn (DirectDebitFailedMail $mail) => $mail->data->reminder);

    expect($this->runBillingOn('2026-11-16')['companiesSuspended'])->toBe(1)
        ->and($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Suspended);

    // GoCardless retries and it goes through: paid, suspension lifted.
    $this->paymentEvent($payment, PaymentStatus::Confirmed, 'confirmed')->assertOk();
    expect($this->fresh($row->invoice)->status)->toBe(InvoiceStatus::Paid)
        ->and($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Active);
});

test('a charge back reverses the recorded payment and the invoice is owed again', function () {
    $payment = createdPayment($this);
    $this->paymentEvent($payment, PaymentStatus::Confirmed, 'confirmed')->assertOk();
    $row = GoCardlessPayment::withoutCompanyScope()->where('gc_payment_id', $payment->id)->sole();

    $this->paymentEvent($payment, PaymentStatus::ChargedBack, 'charged_back')->assertOk();

    $recorded = Payment::withoutCompanyScope()->findOrFail($row->payment_id);
    $invoice = $this->fresh($row->invoice);
    expect($recorded->reversed_at)->not->toBeNull()
        ->and($recorded->unallocated)->toBe('0.00')
        ->and($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->balance)->toBe('60.00')
        ->and($invoice->paid_at)->toBeNull();
    Mail::assertQueued(DirectDebitFailedMail::class, fn (DirectDebitFailedMail $mail) => $mail->data->chargedBack);
});

test('a cancelled mandate emails the owners and staff, pauses the subscription, reminds, then suspends after the grace until a new mandate', function () {
    $mandateId = (string) $this->billingAccountOf($this->company)->gc_mandate_id;
    $this->gc->setMandateStatus($mandateId, MandateStatus::Cancelled);

    $this->webhook([$this->gcEvent('mandates', 'cancelled', ['mandate' => $mandateId])])->assertOk();

    $account = $this->billingAccountOf($this->company);
    expect($account->gc_mandate_status)->toBe(MandateStatus::Cancelled)
        ->and($account->gc_mandate_lost_at)->not->toBeNull();
    Mail::assertQueued(DirectDebitCancelledMail::class, 2);
    Mail::assertQueued(DirectDebitCancelledMail::class, fn (DirectDebitCancelledMail $mail) => $mail->hasTo(config('sspos.staff_email')) && $mail->data->setupUrl === null);

    // Same as no mandate (owner rule 2026-10-05): a 3-day deadline, the subscription paused meanwhile.
    expect($account->mandate_deadline_at?->toIso8601String())->toBe(now()->addDays(3)->toIso8601String())
        ->and($account->gc_subscription_status)->toBe(SubscriptionStatus::Paused);

    $run = $this->runBillingOn('2026-10-26');
    expect($run['noMandateSuspended'])->toBe(0)->and($run['mandateReminders'])->toBe(1);
    Mail::assertQueued(DirectDebitSetupMail::class, fn (DirectDebitSetupMail $mail) => $mail->data->reminder && $mail->data->deadline !== null);

    expect($this->runBillingOn('2026-10-28')['noMandateSuspended'])->toBe(1)
        ->and($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Suspended)
        ->and($this->companyFresh($this->company)->suspension_reason)->toBe('Direct Debit cancelled and not replaced');
    expect($this->runBillingOn('2026-10-29')['noMandateSuspended'])->toBe(0);

    // A new mandate replaces it: the suspension is lifted at once and the subscription moves to the new mandate.
    $newMandate = $this->setUpMandate($this->company);
    $account = $this->billingAccountOf($this->company);
    expect($account->hasUsableMandate())->toBeTrue()
        ->and($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Trial) // back to what it was
        ->and($account->gc_subscription_id)->not->toBe($this->subscription->id)
        ->and($this->gc->subscription((string) $account->gc_subscription_id)->mandateId)->toBe($newMandate)
        ->and($this->gc->subscription($this->subscription->id)->status)->toBe(SubscriptionStatus::Cancelled);
});
