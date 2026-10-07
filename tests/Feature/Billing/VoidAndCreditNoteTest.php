<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Actions\DeleteDraftInvoice;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Mail\Mailables\LicenceRenewedMail;
use App\Domain\Shared\Models\AuditLog;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

beforeEach(function () {
    Mail::fake();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
    $this->company = $this->payingTenant(tills: 3);
});

test('voiding an issued invoice keeps its number, clears the balance and records why', function () {
    $invoice = $this->issuedFor($this->company);

    [$void, $draft] = $this->voidIt($invoice, '  Wrong number of tills  ');

    expect($draft)->toBeNull()
        ->and($void->number)->toBe('INV-000001')
        ->and($void->status)->toBe(InvoiceStatus::Void)
        ->and($void->balance)->toBe('0.00')
        ->and($void->amount_paid)->toBe('0.00')
        ->and($void->total)->toBe('90.00')
        ->and($void->void_reason)->toBe('Wrong number of tills')
        ->and($void->voided_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'invoice.voided')->where('subject_id', $invoice->id)->sole()->meta['reason'])->toBe('Wrong number of tills');
});

test('a void needs a reason', function () {
    $invoice = $this->issuedFor($this->company);

    expect(fn () => $this->voidIt($invoice, '   '))->toThrow(ValidationException::class, 'Enter why the invoice is void.')
        ->and($this->fresh($invoice)->status)->toBe(InvoiceStatus::Issued);
});

test('money already paid on a voided invoice goes back to credit', function () {
    $invoice = $this->issuedFor($this->company);
    $payment = $this->pay($this->company, '40.00')->payment;

    $this->voidIt($invoice);

    $allocation = PaymentAllocation::withoutCompanyScope()->where('invoice_id', $invoice->id)->sole();

    expect(Payment::withoutCompanyScope()->findOrFail($payment->id)->unallocated)->toBe('40.00')
        ->and($allocation->released_at)->not->toBeNull()
        ->and($allocation->amount)->toBe('40.00')
        ->and($this->fresh($invoice)->amount_paid)->toBe('0.00')
        ->and(AuditLog::query()->where('action', 'invoice.voided')->sole()->meta['released_to_credit'])->toBe('40.00');
});

test('"void and re-issue" makes a corrected draft with the same lines that uses the released credit', function () {
    $invoice = $this->issuedFor($this->company);
    $this->pay($this->company, '40.00');

    [$void, $draft] = $this->voidIt($invoice, 'Wrong period', redraft: true);

    expect($draft)->toBeInstanceOf(Invoice::class)
        ->and($draft->status)->toBe(InvoiceStatus::Draft)
        ->and($draft->number)->toBeNull()
        ->and($draft->replaces_invoice_id)->toBe($void->id)
        ->and($draft->total)->toBe('90.00')
        ->and($draft->balance)->toBe('90.00')
        ->and($draft->period_start->format('Y-m-d'))->toBe('2026-11-01')
        ->and($draft->lines->pluck('licence_id')->all())->toBe($void->lines->pluck('licence_id')->all())
        ->and($draft->lines->pluck('description')->all())->toBe($void->lines->pluck('description')->all())
        ->and(InvoiceLine::withoutCompanyScope()->where('invoice_id', $void->id)->count())->toBe(3);

    $reissued = app(IssueInvoice::class)->handle($draft);

    expect($reissued->number)->toBe('INV-000002')
        ->and($reissued->amount_paid)->toBe('40.00')
        ->and($reissued->balance)->toBe('50.00')
        ->and($reissued->status)->toBe(InvoiceStatus::PartiallyPaid);
});

test('the void route with "re-issue" opens the new draft', function () {
    $this->withoutVite();
    $invoice = $this->issuedFor($this->company);

    $response = $this->actingAs($this->admin(AdminRole::Accounts), 'admin')
        ->post(route('admin.billing.invoices.void', $invoice->id), ['reason' => 'Wrong tills', 'redraft' => true]);

    $draft = Invoice::withoutCompanyScope()->where('replaces_invoice_id', $invoice->id)->sole();

    $response->assertRedirect(route('admin.billing.invoices.show', $draft->id))
        ->assertSessionHas('success', 'INV-000001 is void. Here is a corrected draft to edit and issue.');
});

test('paid invoices cannot be voided', function () {
    $invoice = $this->issuedFor($this->company);
    $this->pay($this->company, '90.00');

    expect(fn () => $this->voidIt($invoice))->toThrow(ValidationException::class, 'INV-000001 is paid, so it cannot be voided.')
        ->and($this->fresh($invoice)->status)->toBe(InvoiceStatus::Paid);
});

test('void invoices cannot be voided again', function () {
    $invoice = $this->issuedFor($this->company);
    $this->voidIt($invoice);

    expect(fn () => $this->voidIt($invoice))->toThrow(ValidationException::class, 'INV-000001 is already void.');
});

test('drafts are deleted, not voided', function () {
    $draft = $this->draftFor($this->company);

    expect(fn () => $this->voidIt($draft))->toThrow(ValidationException::class, 'A draft has no number yet: delete it instead.');

    app(DeleteDraftInvoice::class)->handle($draft);

    expect(Invoice::withoutCompanyScope()->find($draft->id))->toBeNull()
        ->and(InvoiceLine::withoutCompanyScope()->where('invoice_id', $draft->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'invoice.deleted')->where('subject_id', $draft->id)->exists())->toBeTrue();
});

test('the delete route removes a draft and returns to the tenant billing tab', function () {
    $this->withoutVite();
    $draft = $this->draftFor($this->company);

    $this->actingAs($this->admin(AdminRole::Accounts), 'admin')
        ->delete(route('admin.billing.invoices.destroy', $draft->id))
        ->assertRedirect(route('admin.tenants.show', ['company' => $this->company->id, 'tab' => 'billing']));

    expect(Invoice::withoutCompanyScope()->count())->toBe(0);
});

test('a credit note reduces what is owed, split into net and VAT at the invoice rate', function () {
    $invoice = $this->issuedFor($this->company);

    $note = $this->creditIt($invoice, '15.00', 'Two days of downtime');
    $after = $this->fresh($invoice);

    expect($note->number)->toBe('CN-000001')
        ->and($note->total)->toBe('15.00')
        ->and($note->net)->toBe('12.50')
        ->and($note->vat)->toBe('2.50')
        ->and($note->reason)->toBe('Two days of downtime')
        ->and($note->invoice_id)->toBe($invoice->id)
        ->and($after->amount_credited)->toBe('15.00')
        ->and($after->balance)->toBe('75.00')
        ->and($after->status)->toBe(InvoiceStatus::PartiallyPaid)
        ->and(AuditLog::query()->where('action', 'credit_note.issued')->where('subject_id', $invoice->id)->sole()->meta['number'])->toBe('CN-000001');
});

test('a credit note on a zero-VAT invoice is all net', function () {
    $this->setVat(true, false, $this->company);
    $invoice = $this->issuedFor($this->company); // £75.00, no VAT

    $note = $this->creditIt($invoice, '10.00');

    expect($note->net)->toBe('10.00')->and($note->vat)->toBe('0.00');
});

test('a credit note that clears the balance settles the invoice and renews its licences', function () {
    $invoice = $this->issuedFor($this->company);
    $this->pay($this->company, '60.00');

    $this->creditIt($this->fresh($invoice), '30.00');
    $after = $this->fresh($invoice);

    expect($after->status)->toBe(InvoiceStatus::Paid)
        ->and($after->balance)->toBe('0.00')
        ->and($after->amount_paid)->toBe('60.00')
        ->and($after->amount_credited)->toBe('30.00')
        ->and($after->licences_renewed_at)->not->toBeNull()
        ->and($this->licenceFresh($this->licencesOf($this->company)[0])->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-11-30'));

    Mail::assertQueued(LicenceRenewedMail::class, fn (LicenceRenewedMail $mail) => $mail->data->reference === 'INV-000001');
});

test('a credit note cannot be more than the balance', function () {
    $invoice = $this->issuedFor($this->company);
    $this->pay($this->company, '50.00');

    expect(fn () => $this->creditIt($this->fresh($invoice), '40.01'))->toThrow(ValidationException::class, 'The credit cannot be more than the £40.00 still owed.')
        ->and(CreditNote::withoutCompanyScope()->count())->toBe(0)
        ->and($this->sequenceValue('credit_note'))->toBe(0);
});

test('crediting a whole unpaid invoice is refused: void it instead', function () {
    $invoice = $this->issuedFor($this->company);

    expect(fn () => $this->creditIt($invoice, '90.00'))->toThrow(ValidationException::class, 'This would credit the whole invoice. Void it instead.')
        ->and($this->fresh($invoice)->status)->toBe(InvoiceStatus::Issued);
});

test('credit notes only go on open invoices and need a reason and an amount', function () {
    $draft = $this->draftFor($this->company);
    expect(fn () => $this->creditIt($draft, '5.00'))->toThrow(ValidationException::class, 'Credit notes can only go on an issued invoice');

    $invoice = app(IssueInvoice::class)->handle($draft);
    expect(fn () => $this->creditIt($invoice, '5.00', ' '))->toThrow(ValidationException::class, 'Enter the reason for the credit.')
        ->and(fn () => $this->creditIt($invoice, '0.00'))->toThrow(ValidationException::class, 'Enter an amount above £0.00.');

    $this->voidIt($invoice);
    expect(fn () => $this->creditIt($invoice, '5.00'))->toThrow(ValidationException::class, 'Credit notes can only go on an issued invoice');

    expect(CreditNote::withoutCompanyScope()->count())->toBe(0);
});

test('the credit note route accepts "£15" and flashes the note number', function () {
    $this->withoutVite();
    $invoice = $this->issuedFor($this->company);

    $this->actingAs($this->admin(), 'admin')
        ->post(route('admin.billing.invoices.credit', $invoice->id), ['amount' => '£15', 'reason' => 'Goodwill'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Credit note CN-000001 for £15.00 issued.');

    $this->actingAs($this->admin(), 'admin')
        ->post(route('admin.billing.invoices.credit', $invoice->id), ['amount' => '0', 'reason' => ''])
        ->assertSessionHasErrors(['amount', 'reason']);

    expect($this->fresh($invoice)->balance)->toBe('75.00');
});

test('"void and re-issue" keeps the kind of invoice: a void setup fee is re-drafted as a setup fee', function () {
    $invoice = $this->issuedFor($this->company);
    $invoice->forceFill(['kind' => InvoiceKind::SetupFee])->save();

    [, $draft] = $this->voidIt($invoice->fresh(), 'Setup fee for two tills', redraft: true);

    expect($draft->kind)->toBe(InvoiceKind::SetupFee)
        ->and($draft->currency)->toBe($invoice->currency);
});
