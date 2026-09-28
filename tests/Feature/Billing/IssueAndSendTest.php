<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\SendInvoice;
use App\Domain\Billing\Actions\UpdateBillingSettings;
use App\Domain\Billing\Data\BillingSettingsInput;
use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Support\InvoicePdf;
use App\Domain\Mail\Mailables\InvoiceMail;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\Shared\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
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

test('issuing numbers and dates the invoice (London today + payment terms) and snapshots who it is billed to', function () {
    $company = $this->payingTenant(tills: 3);

    $invoice = $this->issuedFor($company);

    expect($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->number)->toBe('INV-000001')
        ->and($invoice->issue_date->format('Y-m-d'))->toBe('2026-10-24')
        ->and($invoice->due_date->format('Y-m-d'))->toBe('2026-10-31')
        ->and($invoice->bill_to_name)->toBe('Khan Mini Mart Ltd')
        ->and($invoice->bill_to_emails)->toBe([$this->ownerOf($company)->email])
        ->and($invoice->issued_at)->not->toBeNull()
        ->and($invoice->sent_count)->toBe(1)
        ->and($invoice->balance)->toBe('90.00');

    expect(AuditLog::query()->where('action', 'invoice.issued')->where('subject_id', $invoice->id)->count())->toBe(1);
});

test('the issue date is the London calendar day, not the UTC one', function () {
    // 00:30 on 25 Oct in London is still 24 Oct (23:30) in UTC.
    $this->travelTo(CarbonImmutable::parse('2026-10-25 00:30:00', 'Europe/London'));
    $company = $this->payingTenant(tills: 1);

    $invoice = $this->issuedFor($company);

    expect(CarbonImmutable::now()->utc()->format('Y-m-d'))->toBe('2026-10-24')
        ->and($invoice->issue_date->format('Y-m-d'))->toBe('2026-10-25')
        ->and($invoice->due_date->format('Y-m-d'))->toBe('2026-11-01');
});

test('payment terms and the billing name come from the billing settings, and later changes do not alter an issued invoice', function () {
    $company = $this->payingTenant(tills: 1);
    $settings = fn (string $name, int $terms) => new BillingSettingsInput($name, "1 Accounts Road\nLeeds", [], BillingCycle::Monthly, $terms, true);
    app(UpdateBillingSettings::class)->handle($company, $settings('Khan Holdings', 14));

    $invoice = $this->issuedFor($company);
    app(UpdateBillingSettings::class)->handle($company, $settings('Someone Else', 30));

    $after = $this->fresh($invoice);

    expect($after->due_date->format('Y-m-d'))->toBe('2026-11-07')
        ->and($after->bill_to_name)->toBe('Khan Holdings')
        ->and($after->bill_to_address)->toBe("1 Accounts Road\nLeeds");
});

test('issuing emails the invoice to the active owners with the PDF', function () {
    $company = $this->payingTenant(tills: 3);
    $owner = $this->ownerOf($company);

    $invoice = $this->issuedFor($company);

    Mail::assertQueued(InvoiceMail::class, 1);
    Mail::assertQueued(InvoiceMail::class, function (InvoiceMail $mail) use ($owner, $invoice, $company) {
        $attachments = $mail->attachments();
        $bytes = $attachments[0]->attachWith(fn () => null, fn (Closure $data) => $data());

        return $mail->hasTo($owner->email)
            && $mail->data->invoiceNumber === 'INV-000001'
            && $mail->data->total === '90.00'
            && $mail->data->balance === '90.00'
            && $mail->data->tillCount === 3
            && $mail->data->companyId === $company->id
            && $mail->data->pdfKey === $invoice->id
            && $mail->data->resent === false
            && str_contains($mail->subjectLine(), 'INV-000001')
            && str_contains($mail->subjectLine(), 'due 31 October 2026')
            && count($attachments) === 1
            && $attachments[0] instanceof Attachment
            && $attachments[0]->as === 'INV-000001.pdf'
            && $attachments[0]->mime === 'application/pdf'
            && str_starts_with((string) $bytes, '%PDF');
    });
});

test('billing emails replace the owners as invoice recipients', function () {
    $company = $this->payingTenant(tills: 1);
    $this->useBillingEmails($company, ['accounts@khan.test', 'boss@khan.test']);

    $invoice = $this->issuedFor($company);

    Mail::assertQueued(InvoiceMail::class, 2);
    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->hasTo('accounts@khan.test'));
    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->hasTo('boss@khan.test'));
    Mail::assertNotQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->hasTo($this->ownerOf($company)->email));

    expect($invoice->bill_to_emails)->toBe(['accounts@khan.test', 'boss@khan.test']);
});

test('"Send again" emails it again, counts the send and audits it', function () {
    $company = $this->payingTenant(tills: 1);
    $invoice = $this->issuedFor($company);

    $this->travelTo(CarbonImmutable::parse('2026-10-26 09:00:00', 'Europe/London'));
    $count = app(SendInvoice::class)->handle($this->fresh($invoice));

    $after = $this->fresh($invoice);

    expect($count)->toBe(1)
        ->and($after->sent_count)->toBe(2)
        ->and($after->last_sent_at->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String());

    Mail::assertQueued(InvoiceMail::class, 2);
    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->data->resent === true);

    $sent = AuditLog::query()->where('action', 'invoice.sent')->where('subject_id', $invoice->id)->get();
    expect($sent)->toHaveCount(2)
        ->and($sent->pluck('meta.resent')->sort()->values()->all())->toBe([false, true]);
});

test('the send route answers with a flash message', function () {
    $this->withoutVite();
    $company = $this->payingTenant(tills: 1);
    $invoice = $this->issuedFor($company);

    $this->actingAs($this->admin(AdminRole::Accounts), 'admin')
        ->from(route('admin.billing.invoices.show', $invoice->id))
        ->post(route('admin.billing.invoices.send', $invoice->id))
        ->assertRedirect(route('admin.billing.invoices.show', $invoice->id))
        ->assertSessionHas('success', 'INV-000001 sent again to 1 address.');

    expect($this->fresh($invoice)->sent_count)->toBe(2);
});

test('drafts and void invoices cannot be sent', function () {
    $company = $this->payingTenant(tills: 1);
    $draft = $this->draftFor($company);

    expect(fn () => app(SendInvoice::class)->handle($draft))->toThrow(ValidationException::class, 'Issue the invoice before sending it.');

    $issued = app(IssueInvoice::class)->handle($draft);
    [$void] = $this->voidIt($issued);

    expect(fn () => app(SendInvoice::class)->handle($void))->toThrow(ValidationException::class, 'INV-000001 is void, so it cannot be sent.');

    Mail::assertQueued(InvoiceMail::class, 1);
    expect($this->fresh($void)->sent_count)->toBe(1);
});

test('an invoice with nobody to email is still issued, and "Send again" explains why it cannot send', function () {
    $company = $this->payingTenant(tills: 1);
    $company->users()->updateExistingPivot($this->ownerOf($company)->id, ['is_active' => false]);

    $invoice = $this->issuedFor($company);

    expect($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->number)->toBe('INV-000001')
        ->and($invoice->sent_count)->toBe(0)
        ->and($invoice->bill_to_emails)->toBe([]);

    Mail::assertNotQueued(InvoiceMail::class);

    expect(fn () => app(SendInvoice::class)->handle($invoice))->toThrow(ValidationException::class, 'There is no billing email or active owner');
});

test('issuing without sending leaves it unsent', function () {
    $company = $this->payingTenant(tills: 1);

    $invoice = app(IssueInvoice::class)->handle($this->draftFor($company), send: false);

    expect($invoice->status)->toBe(InvoiceStatus::Issued)->and($invoice->sent_count)->toBe(0);
    Mail::assertNotQueued(InvoiceMail::class);
});

test('the invoice PDF renders from the invoice', function () {
    $invoice = $this->issuedFor($this->payingTenant(tills: 2));

    $bytes = app(InvoicePdf::class)->render($invoice);

    expect($bytes)->toStartWith('%PDF')
        ->and(strlen($bytes))->toBeGreaterThan(1000)
        ->and(InvoicePdf::filename($invoice))->toBe('INV-000001.pdf')
        ->and(app(InvoicePdf::class)->renderAttachment('01ZZZZZZZZZZZZZZZZZZZZZZZZ'))->toBeNull();
});

test('the PDF route downloads the invoice as INV-000001.pdf, or shows it inline', function () {
    $invoice = $this->issuedFor($this->payingTenant(tills: 1));
    $admin = $this->admin(AdminRole::Accounts);

    $download = $this->actingAs($admin, 'admin')->get(route('admin.billing.invoices.pdf', $invoice->id));

    $download->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename="INV-000001.pdf"');
    expect($download->getContent())->toStartWith('%PDF')
        ->and($download->headers->get('Cache-Control'))->toContain('no-store');

    $this->actingAs($admin, 'admin')->get(route('admin.billing.invoices.pdf', ['invoice' => $invoice->id, 'inline' => 1]))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename="INV-000001.pdf"');
});

test('a draft PDF is named after the draft id', function () {
    $draft = $this->draftFor($this->payingTenant(tills: 1));

    $this->actingAs($this->admin(), 'admin')->get(route('admin.billing.invoices.pdf', $draft->id))
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="draft-'.strtolower($draft->id).'.pdf"');
});

test('the email log row for an invoice holds only non-secret facts: no keys, no PDF', function () {
    // Real (array) mailer and sync queue: the branded mailable opens its email_logs row and is sent.
    Mail::swap(new MailManager(app()));
    config(['mail.default' => 'array', 'queue.default' => 'sync']);

    $company = $this->payingTenant(tills: 2);
    $invoice = $this->issuedFor($company);

    $log = EmailLog::query()->where('template', 'invoice')->sole();

    expect($log->company_id)->toBe($company->id)
        ->and($log->subject)->toContain('INV-000001')
        ->and($log->to)->toContain($this->ownerOf($company)->email)
        ->and(array_keys($log->meta))->toEqualCanonicalizing(['business', 'invoice', 'total', 'balance', 'due', 'resent'])
        ->and($log->meta['invoice'])->toBe('INV-000001')
        ->and($log->meta['total'])->toBe('60.00')
        ->and($log->meta['due'])->toBe('2026-10-31');

    $logs = json_encode(DB::table('email_logs')->get()->all(), JSON_THROW_ON_ERROR);

    expect($this->keysIn($logs))->toBe([])
        ->and($this->keysIn($this->storedText()))->toBe([])
        ->and($logs)->not->toContain('%PDF')
        ->and($logs)->not->toContain('JVBERi') // base64 of "%PDF"
        ->and(strlen((string) DB::table('email_logs')->where('id', $log->id)->value('meta')))->toBeLessThan(500);

    expect($this->fresh($invoice)->sent_count)->toBe(1);
});
