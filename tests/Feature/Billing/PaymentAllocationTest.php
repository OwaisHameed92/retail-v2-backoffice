<?php

use App\Domain\Billing\Actions\ApplyCredit;
use App\Domain\Billing\Actions\RecordPayment;
use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Data\NewPayment;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Mail\Mailables\InvoiceMail;
use App\Domain\Shared\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'Europe/London'));
    $this->setVat(true);
});

test('a partial payment leaves the invoice partly paid with the rest owed', function () {
    $company = $this->payingTenant(tills: 3);
    $invoice = $this->issuedFor($company); // £90.00

    $result = $this->pay($company, '40.00');
    $after = $this->fresh($invoice);

    expect($after->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($after->amount_paid)->toBe('40.00')
        ->and($after->balance)->toBe('50.00')
        ->and($after->paid_at)->toBeNull()
        ->and($result->payment->number)->toBe('PAY-000001')
        ->and($result->payment->amount)->toBe('40.00')
        ->and($result->payment->unallocated)->toBe('0.00')
        ->and($result->credit)->toBe('0.00')
        ->and($result->paidInvoices)->toBe([])
        ->and($result->licencesRenewed)->toBe(0);

    // Nothing renewed yet.
    expect($this->licenceFresh($this->licencesOf($company)[0])->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-10-31'));
});

test('paying the exact balance pays the invoice', function () {
    $company = $this->payingTenant(tills: 3);
    $invoice = $this->issuedFor($company);

    $this->pay($company, '40.00');
    $result = $this->pay($company, '50.00');
    $after = $this->fresh($invoice);

    expect($after->status)->toBe(InvoiceStatus::Paid)
        ->and($after->amount_paid)->toBe('90.00')
        ->and($after->balance)->toBe('0.00')
        ->and($after->paid_at)->not->toBeNull()
        ->and($result->paidInvoices)->toHaveCount(1)
        ->and($result->paidInvoices[0]->number)->toBe('INV-000001')
        ->and($result->licencesRenewed)->toBe(3)
        ->and(PaymentAllocation::withoutCompanyScope()->where('invoice_id', $invoice->id)->pluck('amount')->all())->toBe(['40.00', '50.00']);

    $audit = AuditLog::query()->where('action', 'payment.recorded')->where('subject_id', $result->payment->id)->sole();
    expect($audit->meta['allocations'])->toBeIgnoringKeyOrder([['invoice' => 'INV-000001', 'amount' => '50.00']])
        ->and($audit->meta['credit'])->toBe('0.00');
});

test('over-payment is kept as credit and pays the next invoice as soon as it is issued', function () {
    $company = $this->payingTenant(tills: 3);
    $this->issuedFor($company);

    $result = $this->pay($company, '180.00');

    expect($result->credit)->toBe('90.00')
        ->and($result->payment->unallocated)->toBe('90.00');

    // A month later the December invoice is issued and paid from the credit straight away.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:00:00', 'Europe/London'));
    $next = $this->issuedFor($company);

    expect($next->number)->toBe('INV-000002')
        ->and($next->period_start->format('Y-m-d'))->toBe('2026-12-01')
        ->and($next->status)->toBe(InvoiceStatus::Paid)
        ->and($next->amount_paid)->toBe('90.00')
        ->and($next->balance)->toBe('0.00')
        ->and(Payment::withoutCompanyScope()->findOrFail($result->payment->id)->unallocated)->toBe('0.00')
        ->and($this->licenceFresh($this->licencesOf($company)[0])->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-12-31'));

    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->data->invoiceNumber === 'INV-000002' && $mail->data->status === 'paid');
});

test('credit smaller than the new invoice leaves it partly paid', function () {
    $company = $this->payingTenant(tills: 3);
    $this->issuedFor($company);
    $this->pay($company, '100.00');

    $this->travelTo(CarbonImmutable::parse('2026-11-24 10:00:00', 'Europe/London'));
    $next = $this->issuedFor($company);

    expect($next->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($next->amount_paid)->toBe('10.00')
        ->and($next->balance)->toBe('80.00');
});

test('credit is not used on issue when apply_credit_on_issue is off', function () {
    config(['billing.apply_credit_on_issue' => false]);
    $company = $this->payingTenant(tills: 3);
    $this->pay($company, '50.00'); // nothing open: all credit

    $invoice = $this->issuedFor($company);

    expect($invoice->balance)->toBe('90.00')->and($invoice->status)->toBe(InvoiceStatus::Issued);
});

test('a payment with nothing open becomes credit', function () {
    $company = $this->payingTenant(tills: 1);

    $result = $this->pay($company, '25.50');

    expect($result->credit)->toBe('25.50')
        ->and($result->paidInvoices)->toBe([])
        ->and(PaymentAllocation::withoutCompanyScope()->count())->toBe(0);
});

test('one payment is spread over open invoices, earliest due date first', function () {
    $company = $this->payingTenant(tills: 1); // £30.00 invoices

    // Issued first but due later (30-day terms)...
    $account = $this->billingAccountOf($company);
    $account->payment_terms_days = 30;
    $account->save();
    $later = $this->issuedFor($company);

    // ...then one due sooner.
    $account->payment_terms_days = 7;
    $account->save();
    $sooner = $this->issuedFor($company, new NewInvoice(allowOverlap: true));

    $result = $this->pay($company, '40.00');

    expect($this->fresh($sooner)->status)->toBe(InvoiceStatus::Paid)
        ->and($this->fresh($later)->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($this->fresh($later)->balance)->toBe('20.00')
        ->and($result->paidInvoices)->toHaveCount(1)
        ->and($result->paidInvoices[0]->id)->toBe($sooner->id)
        ->and($result->credit)->toBe('0.00');
});

test('staff can choose which invoices a payment goes to; the rest is credit', function () {
    $company = $this->payingTenant(tills: 1);
    $first = $this->issuedFor($company);
    $second = $this->issuedFor($company, new NewInvoice(allowOverlap: true));

    $result = $this->pay($company, '50.00', [$second->id => '30.00', $first->id => '5.00']);

    expect($this->fresh($second)->status)->toBe(InvoiceStatus::Paid)
        ->and($this->fresh($first)->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and($this->fresh($first)->balance)->toBe('25.00')
        ->and($result->credit)->toBe('15.00');
});

test('zero amounts in the chosen allocations are ignored', function () {
    $company = $this->payingTenant(tills: 1);
    $first = $this->issuedFor($company);

    $result = $this->pay($company, '10.00', [$first->id => '0.00']);

    expect($this->fresh($first)->balance)->toBe('30.00')->and($result->credit)->toBe('10.00');
});

test('chosen allocations are validated and nothing is recorded when they are wrong', function (Closure $allocations, string $message) {
    $company = $this->payingTenant('Alpha Stores', 1, 'ALP');
    $other = $this->payingTenant('Bravo Mart', 1, 'BRV');
    $invoice = $this->issuedFor($company);
    $theirs = $this->issuedFor($other);

    expect(fn () => $this->pay($company, '20.00', $allocations($invoice, $theirs)))->toThrow(ValidationException::class, $message);

    expect(Payment::withoutCompanyScope()->count())->toBe(0)
        ->and(PaymentAllocation::withoutCompanyScope()->count())->toBe(0)
        ->and($this->sequenceValue('payment'))->toBe(0)
        ->and($this->fresh($invoice)->balance)->toBe('30.00')
        ->and($this->fresh($theirs)->balance)->toBe('30.00');
})->with([
    'another company\'s invoice' => [fn ($mine, $theirs) => [$theirs->id => '10.00'], 'not open for this business'],
    'more than the balance' => [fn ($mine, $theirs) => [$mine->id => '30.01'], 'Put between £0.01 and £30.00 on INV-000001'],
    'negative amount' => [fn ($mine, $theirs) => [$mine->id => '-1.00'], 'Put between £0.01 and £30.00'],
    'more than the payment' => [fn ($mine, $theirs) => [$mine->id => '25.00'], 'add up to more than the payment'],
    'unknown invoice' => [fn ($mine, $theirs) => ['01ZZZZZZZZZZZZZZZZZZZZZZZZ' => '1.00'], 'not open for this business'],
]);

test('a paid or void invoice cannot be chosen', function () {
    $company = $this->payingTenant(tills: 1);
    $paid = $this->issuedFor($company);
    $this->pay($company, '30.00');
    $void = $this->issuedFor($company, new NewInvoice(allowOverlap: true));
    $this->voidIt($void);

    expect(fn () => $this->pay($company, '5.00', [$paid->id => '5.00']))->toThrow(ValidationException::class, 'not open')
        ->and(fn () => $this->pay($company, '5.00', [$void->id => '5.00']))->toThrow(ValidationException::class, 'not open');
});

test('amounts must be above zero', function (string $amount) {
    $company = $this->payingTenant(tills: 1);

    expect(fn () => $this->pay($company, $amount))->toThrow(ValidationException::class, 'Enter an amount above £0.00.');
})->with(['0', '0.00', '-5.00', '0.004']);

test('"Apply credit" uses unallocated credit on open invoices', function () {
    config(['billing.apply_credit_on_issue' => false]);
    $company = $this->payingTenant(tills: 3);
    $this->pay($company, '30.00');
    $this->pay($company, '25.00');
    $invoice = $this->issuedFor($company);

    $applied = app(ApplyCredit::class)->handle($company);

    expect($applied)->toBe('55.00')
        ->and($this->fresh($invoice)->balance)->toBe('35.00')
        ->and($this->fresh($invoice)->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and(Payment::withoutCompanyScope()->where('unallocated', '>', 0)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'billing.credit_applied')->sole()->meta['amount'])->toBe('55.00');

    expect(fn () => app(ApplyCredit::class)->handle($company))->toThrow(ValidationException::class, 'has no credit to apply');
});

test('"Apply credit" that covers the invoice settles it', function () {
    config(['billing.apply_credit_on_issue' => false]);
    $company = $this->payingTenant(tills: 1);
    $this->pay($company, '100.00');
    $invoice = $this->issuedFor($company);

    expect(app(ApplyCredit::class)->handle($company))->toBe('30.00')
        ->and($this->fresh($invoice)->status)->toBe(InvoiceStatus::Paid)
        ->and($this->fresh($invoice)->licences_renewed_at)->not->toBeNull();
});

test('the same gateway payment is only ever recorded once', function () {
    $company = $this->payingTenant(tills: 1);
    $invoice = $this->issuedFor($company);
    $payment = fn () => app(RecordPayment::class)->handle($company, new NewPayment(
        method: PaymentMethod::Online,
        amount: '30.00',
        receivedAt: CarbonImmutable::now(),
        gateway: 'stripe',
        gatewayReference: 'pi_3Nabc123',
    ));

    $first = $payment();
    $second = $payment();

    expect($first->duplicate)->toBeFalse()
        ->and($second->duplicate)->toBeTrue()
        ->and($second->payment->id)->toBe($first->payment->id)
        ->and(Payment::withoutCompanyScope()->count())->toBe(1)
        ->and($this->sequenceValue('payment'))->toBe(1)
        ->and($this->fresh($invoice)->status)->toBe(InvoiceStatus::Paid)
        ->and($this->fresh($invoice)->amount_paid)->toBe('30.00');
});

test('a payment dated in the past via the admin form is stored at midday London that day', function () {
    $this->withoutVite();
    $company = $this->payingTenant(tills: 1);
    $invoice = $this->issuedFor($company);

    $this->actingAs($this->admin(), 'admin')->post(route('admin.billing.tenants.payments.store', $company), [
        'method' => 'bankTransfer',
        'amount' => '£30',
        'received_on' => '2026-10-20',
        'reference' => 'BACS 123',
        'allocation' => 'auto',
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $payment = Payment::withoutCompanyScope()->sole();

    expect($payment->method)->toBe(PaymentMethod::BankTransfer)
        ->and($payment->amount)->toBe('30.00')
        ->and($payment->received_at->toDateTimeString())->toBe(CarbonImmutable::parse('2026-10-20 12:00:00', BillingDates::TIMEZONE)->utc()->toDateTimeString())
        ->and($payment->reference)->toBe('BACS 123')
        ->and($this->fresh($invoice)->status)->toBe(InvoiceStatus::Paid);
});

test('the admin form refuses future dates, online payments and bad amounts', function () {
    $this->withoutVite();
    $company = $this->payingTenant(tills: 1);

    $this->actingAs($this->admin(), 'admin')->post(route('admin.billing.tenants.payments.store', $company), [
        'method' => 'online',
        'amount' => '12.345',
        'received_on' => '2026-10-25',
        'allocation' => 'auto',
    ])->assertSessionHasErrors(['method', 'amount', 'received_on']);

    expect(Payment::withoutCompanyScope()->count())->toBe(0);
});
