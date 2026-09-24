<?php

use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Mail\Mailables\AccountReactivatedMail;
use App\Domain\Mail\Mailables\AccountSuspendedMail;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\ActivateCompany;
use App\Domain\Tenancy\Actions\SuspendCompany;
use App\Domain\Tenancy\Actions\UnsuspendCompany;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

beforeEach(function () {
    Mail::fake();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);

    // An active customer with INV-000001 (£90.00) issued today, due 31 Oct.
    $this->company = app(ActivateCompany::class)->handle($this->payingTenant(tills: 3));
    $this->invoice = $this->issuedFor($this->company);
});

test('nothing happens on the due date itself', function () {
    $result = $this->runBillingOn('2026-10-31');

    expect($result['invoicesOverdue'])->toBe(0)
        ->and($this->fresh($this->invoice)->status)->toBe(InvoiceStatus::Issued)
        ->and($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Active);
});

test('the day after the due date the invoice and the company become overdue, once', function () {
    $result = $this->runBillingOn('2026-11-01');
    $invoice = $this->fresh($this->invoice);

    expect($result['invoicesOverdue'])->toBe(1)
        ->and($invoice->status)->toBe(InvoiceStatus::Overdue)
        ->and($invoice->overdue_at)->not->toBeNull()
        ->and($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Overdue);

    $audit = AuditLog::query()->where('action', 'company.overdue')->where('subject_id', $this->company->id)->sole();
    expect($audit->meta['reason'])->toBe('Invoice INV-000001 is overdue');

    $again = $this->runBillingOn('2026-11-01');

    expect($again['invoicesOverdue'])->toBe(0)
        ->and(AuditLog::query()->where('action', 'company.overdue')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'invoice.overdue')->count())->toBe(1);
});

test('a partly paid invoice past its due date is overdue too, and stays overdue until paid', function () {
    $this->pay($this->company, '10.00');
    $this->runBillingOn('2026-11-01');

    expect($this->fresh($this->invoice)->status)->toBe(InvoiceStatus::Overdue);

    $this->pay($this->company, '20.00');

    expect($this->fresh($this->invoice)->status)->toBe(InvoiceStatus::Overdue)
        ->and($this->fresh($this->invoice)->balance)->toBe('60.00')
        ->and($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Overdue);
});

test('fourteen days after the due date the company is still open; on the fifteenth it is suspended', function () {
    $this->runBillingOn('2026-11-01');

    expect($this->runBillingOn('2026-11-14')['companiesSuspended'])->toBe(0)
        ->and($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Overdue);

    $result = $this->runBillingOn('2026-11-15');
    $company = $this->companyFresh($this->company);
    $account = $this->billingAccountOf($company);

    expect($result['companiesSuspended'])->toBe(1)
        ->and($company->status)->toBe(CompanyStatus::Suspended)
        ->and($company->suspension_reason)->toBe('Invoice INV-000001 unpaid')
        ->and($company->suspended_from_status)->toBe(CompanyStatus::Overdue)
        ->and($account->billing_suspended_at->getTimestamp())->toBe($company->suspended_at->getTimestamp())
        ->and($account->suspension_invoice_id)->toBe($this->invoice->id)
        ->and($this->fresh($this->invoice)->suspension_triggered_at)->not->toBeNull();

    Mail::assertQueued(AccountSuspendedMail::class, 1);
    Mail::assertQueued(AccountSuspendedMail::class, fn (AccountSuspendedMail $mail) => $mail->hasTo($this->ownerOf($company)->email)
        && $mail->data->reason === 'Invoice INV-000001 unpaid.'
        && $mail->data->amountDue === '90.00');
});

test('billing:run straight after a missed fortnight marks overdue and suspends in one go', function () {
    $result = $this->runBillingOn('2026-11-20');

    expect($result['invoicesOverdue'])->toBe(1)
        ->and($result['companiesSuspended'])->toBe(1)
        ->and($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Suspended);
});

test('running again does not suspend or email twice', function () {
    $this->runBillingOn('2026-11-15');
    $again = $this->runBillingOn('2026-11-15');
    $later = $this->runBillingOn('2026-11-16');

    expect($again['companiesSuspended'])->toBe(0)
        ->and($later['companiesSuspended'])->toBe(0)
        ->and(AuditLog::query()->where('action', 'company.suspended')->count())->toBe(1);

    Mail::assertQueued(AccountSuspendedMail::class, 1);
});

test('paying the overdue invoice lifts the billing suspension and makes the company active again', function () {
    $this->runBillingOn('2026-11-15');
    $this->atLondon('2026-11-15 14:00');

    $result = $this->pay($this->company, '90.00');
    $company = $this->companyFresh($this->company);
    $account = $this->billingAccountOf($company);

    expect($result->unsuspended)->toBeTrue()
        ->and($this->fresh($this->invoice)->status)->toBe(InvoiceStatus::Paid)
        ->and($company->status)->toBe(CompanyStatus::Active)
        ->and($company->suspended_at)->toBeNull()
        ->and($company->suspension_reason)->toBeNull()
        ->and($account->billing_suspended_at)->toBeNull()
        ->and($account->suspension_invoice_id)->toBeNull();

    Mail::assertQueued(AccountReactivatedMail::class, 1);
    Mail::assertQueued(AccountReactivatedMail::class, fn (AccountReactivatedMail $mail) => $mail->hasTo($this->ownerOf($company)->email)
        && $mail->data->tillCount === 3
        && $mail->data->activeUntil?->toDateTimeString() === $this->londonEnd('2026-11-30'));
});

test('a partial payment does not lift the suspension', function () {
    $this->runBillingOn('2026-11-15');

    $result = $this->pay($this->company, '89.99');

    expect($result->unsuspended)->toBeFalse()
        ->and($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Suspended);

    Mail::assertNotQueued(AccountReactivatedMail::class);
});

test('paying one of two overdue invoices keeps the suspension until both are paid', function () {
    $second = $this->issuedFor($this->company, new NewInvoice(allowOverlap: true));
    $this->runBillingOn('2026-11-15');

    $first = $this->pay($this->company, '90.00', [$this->invoice->id => '90.00']);
    expect($first->unsuspended)->toBeFalse()
        ->and($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Suspended);

    $last = $this->pay($this->company, '90.00');
    expect($last->unsuspended)->toBeTrue()
        ->and($this->fresh($second)->status)->toBe(InvoiceStatus::Paid)
        ->and($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Active);
});

test('paying an overdue invoice turns an overdue (not suspended) company active', function () {
    $this->runBillingOn('2026-11-01');

    $result = $this->pay($this->company, '90.00');

    expect($result->unsuspended)->toBeFalse()
        ->and($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Active);

    Mail::assertNotQueued(AccountReactivatedMail::class);
});

test('a suspension staff made for another reason is never lifted by a payment', function () {
    $this->runBillingOn('2026-11-01');
    app(SuspendCompany::class)->handle(Company::query()->findOrFail($this->company->id), 'Chargeback under review');

    $result = $this->pay($this->company, '90.00');
    $company = $this->companyFresh($this->company);

    expect($result->unsuspended)->toBeFalse()
        ->and($company->status)->toBe(CompanyStatus::Suspended)
        ->and($company->suspension_reason)->toBe('Chargeback under review');

    Mail::assertNotQueued(AccountReactivatedMail::class);
});

test('a staff re-suspension after a billing suspension is not lifted by the payment either', function () {
    $this->runBillingOn('2026-11-15');
    $this->atLondon('2026-11-15 09:00');
    app(UnsuspendCompany::class)->handle(Company::query()->findOrFail($this->company->id));
    $this->atLondon('2026-11-15 09:05');
    app(SuspendCompany::class)->handle(Company::query()->findOrFail($this->company->id), 'Owner asked us to pause the account');

    $result = $this->pay($this->company, '90.00');
    $company = $this->companyFresh($this->company);

    expect($result->unsuspended)->toBeFalse()
        ->and($company->status)->toBe(CompanyStatus::Suspended)
        ->and($company->suspension_reason)->toBe('Owner asked us to pause the account')
        ->and($this->billingAccountOf($company)->billing_suspended_at)->toBeNull();
});

test('when staff lift a billing suspension by hand, the same invoice does not suspend the company again', function () {
    $this->runBillingOn('2026-11-15');
    $this->atLondon('2026-11-15 11:00');
    app(UnsuspendCompany::class)->handle(Company::query()->findOrFail($this->company->id));

    expect($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Overdue);

    expect($this->runBillingOn('2026-11-16')['companiesSuspended'])->toBe(0)
        ->and($this->runBillingOn('2026-11-30')['companiesSuspended'])->toBe(0)
        ->and($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Overdue);

    Mail::assertQueued(AccountSuspendedMail::class, 1);
});

test('a later unpaid invoice can still suspend the company', function () {
    $this->runBillingOn('2026-11-15');
    $this->atLondon('2026-11-15 11:00');
    app(UnsuspendCompany::class)->handle(Company::query()->findOrFail($this->company->id));
    $second = $this->issuedFor($this->company, new NewInvoice(allowOverlap: true)); // due 22 Nov

    expect($this->runBillingOn('2026-12-06')['companiesSuspended'])->toBe(0);

    $result = $this->runBillingOn('2026-12-07');
    $company = $this->companyFresh($this->company);

    expect($result['companiesSuspended'])->toBe(1)
        ->and($company->status)->toBe(CompanyStatus::Suspended)
        ->and($company->suspension_reason)->toBe('Invoice '.$second->number.' unpaid');

    Mail::assertQueued(AccountSuspendedMail::class, 2);
});

test('voiding the overdue invoice also lifts the billing suspension', function () {
    $this->runBillingOn('2026-11-15');

    $this->voidIt($this->fresh($this->invoice), 'Customer closed two tills in October');

    $company = $this->companyFresh($this->company);

    expect($company->status)->toBe(CompanyStatus::Active)
        ->and($this->billingAccountOf($company)->billing_suspended_at)->toBeNull();

    Mail::assertQueued(AccountReactivatedMail::class, 1);
});

test('a credit note that clears the overdue balance lifts the suspension', function () {
    $this->runBillingOn('2026-11-15');
    $this->pay($this->company, '80.00');

    $this->creditIt($this->fresh($this->invoice), '10.00');

    expect($this->fresh($this->invoice)->status)->toBe(InvoiceStatus::Paid)
        ->and($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Active);

    Mail::assertQueued(AccountReactivatedMail::class, 1);
});

test('cancelled companies are never suspended by billing', function () {
    $this->runBillingOn('2026-11-01');
    $company = Company::query()->findOrFail($this->company->id);
    $company->forceFill(['status' => CompanyStatus::Cancelled])->save();

    expect($this->runBillingOn('2026-11-20')['companiesSuspended'])->toBe(0)
        ->and($this->companyFresh($this->company)->status)->toBe(CompanyStatus::Cancelled);

    Mail::assertNotQueued(AccountSuspendedMail::class);
});
