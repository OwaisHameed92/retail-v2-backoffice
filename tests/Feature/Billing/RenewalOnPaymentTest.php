<?php

use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\SettleInvoice;
use App\Domain\Billing\Actions\UpdateDraftInvoice;
use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Licensing\Actions\RevokeLicence;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Mail\Mailables\LicenceRenewedMail;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Enums\CompanyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'Europe/London'));
    $this->setVat(true);
});

test('paying an invoice renews its licences to 23:59:59 London on the last day of the period', function () {
    $company = $this->payingTenant(tills: 3);
    $invoice = $this->issuedFor($company);

    $this->pay($company, '90.00');

    foreach ($this->licencesOf($company) as $licence) {
        $fresh = $this->licenceFresh($licence);

        // 30 Nov is GMT, so London 23:59:59 is 23:59:59 UTC.
        expect($fresh->expires_at->utc()->toDateTimeString())->toBe('2026-11-30 23:59:59')
            ->and($fresh->status)->toBe(LicenceStatus::Active)
            ->and($fresh->grace_days)->toBe(7);
    }

    $after = $this->fresh($invoice);
    expect($after->licences_renewed_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'invoice.licences_renewed')->where('subject_id', $invoice->id)->sole()->meta)
        ->toMatchArray(['renewed' => 3, 'skipped' => 0, 'until' => '2026-11-30']);
});

test('a period ending in British Summer Time renews to 22:59:59 UTC', function () {
    $company = $this->payingTenant(tills: 1);
    $invoice = $this->issuedFor($company, new NewInvoice(periodStart: CarbonImmutable::parse('2027-05-01'), allowOverlap: true));

    expect($invoice->period_end->format('Y-m-d'))->toBe('2027-05-31');

    $this->pay($company, $invoice->total);

    expect($this->licenceFresh($this->licencesOf($company)[0])->expires_at->utc()->toDateTimeString())->toBe('2027-05-31 22:59:59')
        ->and($this->londonEnd('2027-05-31'))->toBe('2027-05-31 22:59:59');
});

test('only the licences on the invoice are renewed', function () {
    $company = $this->payingTenant(tills: 3);
    [$one, $two, $three] = $this->licencesOf($company);
    $draft = $this->draftFor($company);

    // Staff removed till 3 from the draft.
    $lines = $draft->lines->filter(fn ($line) => $line->licence_id !== $three->id)
        ->map(fn ($line) => ['id' => $line->id, 'description' => $line->description, 'quantity' => $line->quantity, 'unit_price' => $line->unit_price])
        ->values()->all();
    $invoice = app(IssueInvoice::class)->handle(app(UpdateDraftInvoice::class)->handle($draft, null, $lines));

    $this->pay($company, $invoice->total);

    expect($this->licenceFresh($one)->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-11-30'))
        ->and($this->licenceFresh($two)->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-11-30'))
        ->and($this->licenceFresh($three)->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-10-31'));
});

test('a licence already paid beyond the period end is never shortened', function () {
    $company = $this->payingTenant(tills: 2);
    [$one, $two] = $this->licencesOf($company);
    $invoice = $this->issuedFor($company);
    $two->forceFill(['expires_at' => CarbonImmutable::parse($this->londonEnd('2026-12-15'))])->save();

    $result = $this->pay($company, $invoice->total);

    expect($result->licencesRenewed)->toBe(1)
        ->and($this->licenceFresh($one)->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-11-30'))
        ->and($this->licenceFresh($two)->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-12-15'))
        ->and(AuditLog::query()->where('action', 'invoice.licences_renewed')->sole()->meta)->toMatchArray(['renewed' => 1, 'skipped' => 1]);
});

test('a licence revoked after invoicing is skipped', function () {
    $company = $this->payingTenant(tills: 2);
    [$one, $two] = $this->licencesOf($company);
    $invoice = $this->issuedFor($company);
    app(RevokeLicence::class)->handle($two, 'Till stolen');

    $result = $this->pay($company, $invoice->total);
    $revoked = Licence::withoutCompanyScope()->withTrashed()->findOrFail($two->id);

    expect($result->licencesRenewed)->toBe(1)
        ->and($this->fresh($invoice)->status)->toBe(InvoiceStatus::Paid)
        ->and($this->licenceFresh($one)->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-11-30'))
        ->and($revoked->status)->toBe(LicenceStatus::Revoked)
        ->and($revoked->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-10-31'));
});

test('owners get "licences renewed" with the invoice number and the amount paid', function () {
    $company = $this->payingTenant(tills: 3);
    $second = $this->addMember($company, CompanyRole::Owner);
    $manager = $this->addMember($company, CompanyRole::Manager);
    $invoice = $this->issuedFor($company);

    $this->pay($company, '50.00');
    Mail::assertNotQueued(LicenceRenewedMail::class);

    $this->pay($company, '40.00');

    Mail::assertQueued(LicenceRenewedMail::class, 2);
    Mail::assertQueued(LicenceRenewedMail::class, fn (LicenceRenewedMail $mail) => $mail->hasTo($second->email)
        && $mail->data->reference === 'INV-000001'
        && $mail->data->amountPaid === '90.00'
        && count($mail->data->tills) === 3
        && $mail->data->newExpiry->toDateTimeString() === $this->londonEnd('2026-11-30'));
    Mail::assertQueued(LicenceRenewedMail::class, fn (LicenceRenewedMail $mail) => $mail->hasTo($this->ownerOf($company)->email));
    Mail::assertNotQueued(LicenceRenewedMail::class, fn (LicenceRenewedMail $mail) => $mail->hasTo($manager->email));

    expect($invoice->total)->toBe('90.00');
});

test('paying makes a trial company active', function () {
    $company = $this->payingTenant(tills: 1);
    expect($company->status)->toBe(CompanyStatus::Trial);

    $this->pay($company, $this->issuedFor($company)->total);

    expect($this->companyFresh($company)->status)->toBe(CompanyStatus::Active)
        ->and(AuditLog::query()->where('action', 'company.activated')->where('subject_id', $company->id)->exists())->toBeTrue();
});

test('settling is idempotent: licences are renewed and emailed once', function () {
    $company = $this->payingTenant(tills: 2);
    $invoice = $this->issuedFor($company);
    $this->pay($company, $invoice->total);
    $paid = $this->fresh($invoice);
    $stamp = $paid->licences_renewed_at;

    // Someone renews till 1 by hand afterwards; settling again must not touch it.
    [$one] = $this->licencesOf($company);
    $this->licenceFresh($one)->forceFill(['expires_at' => CarbonImmutable::parse($this->londonEnd('2026-11-15'))])->save();

    $renewed = app(SettleInvoice::class)->handle($paid, CarbonImmutable::now()->addHour());

    expect($renewed)->toBe([])
        ->and($this->fresh($invoice)->licences_renewed_at->equalTo($stamp))->toBeTrue()
        ->and($this->licenceFresh($one)->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-11-15'))
        ->and(AuditLog::query()->where('action', 'invoice.paid')->where('subject_id', $invoice->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'invoice.licences_renewed')->count())->toBe(1);

    Mail::assertQueued(LicenceRenewedMail::class, 1);
});

test('an invoice for a period already over is marked paid but renews nothing', function () {
    $company = $this->payingTenant(tills: 1);
    $invoice = $this->issuedFor($company, new NewInvoice(periodStart: CarbonImmutable::parse('2026-09-01'), allowOverlap: true));

    $result = $this->pay($company, $invoice->total);

    expect($this->fresh($invoice)->status)->toBe(InvoiceStatus::Paid)
        ->and($this->fresh($invoice)->licences_renewed_at)->not->toBeNull()
        ->and($result->licencesRenewed)->toBe(0)
        ->and($this->licenceFresh($this->licencesOf($company)[0])->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-10-31'));

    Mail::assertNotQueued(LicenceRenewedMail::class);
});

test('a lapsed till is renewed from the invoice period, not from its old expiry', function () {
    $company = $this->payingTenant(tills: 1, paidUntil: '2026-10-10');
    $invoice = $this->issuedFor($company); // 24 Oct – 23 Nov

    $this->pay($company, $invoice->total);

    expect($this->licenceFresh($this->licencesOf($company)[0])->expires_at->toDateTimeString())->toBe($this->londonEnd('2026-11-23'));
});
