<?php

use App\Domain\Billing\Actions\GenerateDueInvoices;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Mail\Mailables\AccountSuspendedMail;
use App\Domain\Mail\Mailables\InvoiceMail;
use App\Domain\Mail\Mailables\TrialReminderMail;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\CancelCompany;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

beforeEach(function () {
    Mail::fake();
    $this->atLondon('2026-10-01 10:00');
    $this->setVat(true);
});

test('billing:run prints what it did', function () {
    [$due, $late] = $this->billingRunScenario();
    $this->atLondon('2026-10-26 06:00');

    $this->artisan('billing:run')
        ->expectsOutputToContain('Billing run finished.')
        ->expectsOutputToContain('Invoices created: 2')
        ->expectsOutputToContain('Invoices now overdue: 1')
        ->expectsOutputToContain('Businesses suspended: 1')
        ->expectsOutputToContain('Trial reminders sent: 1')
        ->expectsOutputToContain('Trial ended emails sent: 0')
        ->assertSuccessful();

    expect(Invoice::withoutCompanyScope()->where('company_id', $due->id)->where('status', InvoiceStatus::Draft->value)->count())->toBe(1)
        ->and($this->companyFresh($late)->status)->toBe(CompanyStatus::Suspended);
});

test('running billing:run twice on the same day does everything once', function () {
    [$due, $late, $lateInvoice, $trial] = $this->billingRunScenario();
    $this->atLondon('2026-10-26 06:00');

    $this->artisan('billing:run')->assertSuccessful();
    $this->atLondon('2026-10-26 18:00');
    $this->artisan('billing:run')
        ->expectsOutputToContain('Invoices created: 0')
        ->expectsOutputToContain('Invoices now overdue: 0')
        ->expectsOutputToContain('Businesses suspended: 0')
        ->expectsOutputToContain('Trial reminders sent: 0')
        ->assertSuccessful();

    expect(Invoice::withoutCompanyScope()->where('company_id', $due->id)->count())->toBe(1)
        ->and(Invoice::withoutCompanyScope()->where('company_id', $trial->id)->count())->toBe(1)
        ->and(Invoice::withoutCompanyScope()->where('company_id', $late->id)->count())->toBe(1)
        ->and($this->fresh($lateInvoice)->status)->toBe(InvoiceStatus::Overdue)
        ->and(AuditLog::query()->where('action', 'invoice.overdue')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'company.suspended')->count())->toBe(1);

    Mail::assertQueued(AccountSuspendedMail::class, 1);
    Mail::assertQueued(TrialReminderMail::class, 1);
    Mail::assertNotQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->data->invoiceNumber !== $lateInvoice->number);
});

test('a missed day is caught up by the next run', function () {
    [$due, $late, $lateInvoice] = $this->billingRunScenario();

    $result = $this->runBillingOn('2026-10-29');

    // The trial ended yesterday: the missed run is caught up, so the trial company's first invoice is drafted
    // (its period starts today) as well as the paying company's next one.
    expect($result['invoicesCreated'])->toBe(2)
        ->and($result['invoicesOverdue'])->toBe(1)
        ->and($result['companiesSuspended'])->toBe(1)
        ->and($result['trialEnded'])->toBe(1)
        ->and($result['trialReminders'])->toBe(0);

    // The day after, nothing is drafted again: the new invoices cover the next periods.
    expect($this->runBillingOn('2026-10-30')['invoicesCreated'])->toBe(0);
});

test('the next invoice is drafted only within days_before of the tills running out', function () {
    $company = $this->payingTenant(tills: 2); // paid until 31 Oct 23:59:59

    expect($this->runBillingOn('2026-10-24')['invoicesCreated'])->toBe(0)
        ->and($this->runBillingOn('2026-10-25')['invoicesCreated'])->toBe(1);

    $draft = Invoice::withoutCompanyScope()->where('company_id', $company->id)->sole();

    expect($draft->status)->toBe(InvoiceStatus::Draft)
        ->and($draft->auto_generated)->toBeTrue()
        ->and($draft->created_by_admin_id)->toBeNull()
        ->and($draft->period_start->format('Y-m-d'))->toBe('2026-11-01')
        ->and($draft->period_end->format('Y-m-d'))->toBe('2026-11-30')
        ->and($draft->total)->toBe('60.00');

    Mail::assertNotQueued(InvoiceMail::class);
});

test('days_before is configurable', function () {
    config(['billing.generate.days_before' => 10]);
    $this->payingTenant(tills: 1);

    expect($this->runBillingOn('2026-10-21')['invoicesCreated'])->toBe(0)
        ->and($this->runBillingOn('2026-10-22')['invoicesCreated'])->toBe(1);
});

test('no invoice is drafted when one already covers the next period, unless it was voided', function () {
    $company = $this->payingTenant(tills: 1);
    $this->atLondon('2026-10-20 10:00');
    $issued = $this->issuedFor($company);

    expect($this->runBillingOn('2026-10-26')['invoicesCreated'])->toBe(0);

    $this->voidIt($this->fresh($issued));

    expect($this->runBillingOn('2026-10-27')['invoicesCreated'])->toBe(1)
        ->and(Invoice::withoutCompanyScope()->where('status', InvoiceStatus::Draft->value)->count())->toBe(1);
});

test('an existing draft for the next period also stops a second one', function () {
    $company = $this->payingTenant(tills: 1);
    $this->atLondon('2026-10-20 10:00');
    $this->draftFor($company);

    expect($this->runBillingOn('2026-10-26')['invoicesCreated'])->toBe(0)
        ->and(Invoice::withoutCompanyScope()->count())->toBe(1);
});

test('after the invoice is paid the following one is drafted near the new end', function () {
    $company = $this->payingTenant(tills: 1);
    $this->runBillingOn('2026-10-26');
    $draft = Invoice::withoutCompanyScope()->sole();
    app(IssueInvoice::class)->handle($draft);
    $this->pay($company, '30.00'); // renewed to 30 Nov

    expect($this->runBillingOn('2026-11-20')['invoicesCreated'])->toBe(0)
        ->and($this->runBillingOn('2026-11-24')['invoicesCreated'])->toBe(1)
        ->and(Invoice::withoutCompanyScope()->where('status', InvoiceStatus::Draft->value)->sole()->period_start->format('Y-m-d'))->toBe('2026-12-01');
});

test('with auto_issue on, due invoices are issued and emailed straight away', function () {
    config(['billing.generate.auto_issue' => true]);
    $company = $this->payingTenant(tills: 1);

    $this->runBillingOn('2026-10-26');
    $invoice = Invoice::withoutCompanyScope()->where('company_id', $company->id)->sole();

    expect($invoice->status)->toBe(InvoiceStatus::Issued)
        ->and($invoice->number)->toBe('INV-000001')
        ->and($invoice->auto_generated)->toBeTrue()
        ->and($invoice->issued_by_admin_id)->toBeNull()
        ->and($invoice->due_date->format('Y-m-d'))->toBe('2026-11-02');

    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->data->invoiceNumber === 'INV-000001');

    expect($this->runBillingOn('2026-10-27')['invoicesCreated'])->toBe(0)
        ->and($this->sequenceValue('invoice'))->toBe(1);
});

test('cancelled companies and lapsed tills are not invoiced by billing:run', function () {
    $cancelled = $this->payingTenant('Closed Ltd', 1, 'CLD');
    app(CancelCompany::class)->handle($cancelled, 'Sold the shop');
    $this->payingTenant('Lapsed Ltd', 1, 'LPS', paidUntil: '2026-09-30');

    $result = app(GenerateDueInvoices::class)->handle(CarbonImmutable::parse('2026-10-26 06:00', 'Europe/London'));

    expect($result)->toBe([])
        ->and(Invoice::withoutCompanyScope()->count())->toBe(0);
});

test('billing:run is scheduled daily at 06:00, on one server, without overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains((string) $event->command, 'billing:run'));

    expect($events)->toHaveCount(1);

    $event = $events->first();

    expect($event->expression)->toBe('0 6 * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});

test('billing:run is quiet with nothing to do', function () {
    $this->atLondon('2026-10-26 06:00');

    $this->artisan('billing:run')
        ->expectsOutputToContain('Invoices created: 0')
        ->expectsOutputToContain('Businesses suspended: 0')
        ->assertSuccessful();

    expect(Company::query()->count())->toBe(0);
});
