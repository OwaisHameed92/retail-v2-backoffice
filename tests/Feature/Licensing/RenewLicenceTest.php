<?php

use App\Domain\Licensing\Actions\RenewCompanyLicences;
use App\Domain\Licensing\Actions\RenewLicence;
use App\Domain\Licensing\Actions\RevokeLicence;
use App\Domain\Licensing\Actions\SuspendLicence;
use App\Domain\Licensing\Data\RenewalTerm;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Mail\Mailables\LicenceRenewedMail;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\DeactivateRegister;
use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class);

beforeEach(function () {
    Mail::fake();
    // 14:30 London on 10 March 2027 (GMT).
    $this->travelTo(CarbonImmutable::parse('2027-03-10 14:30:00', 'Europe/London'));
});

/** "2027-04-10 23:59:59" in London, as a UTC string. */
function londonEndOfDay(string $date): string
{
    return CarbonImmutable::parse($date.' 23:59:59', 'Europe/London')->utc()->toDateTimeString();
}

test('renewing a trial for a month makes it an active paid licence', function () {
    $licence = $this->activate($this->firstLicence($this->licensedTenant()), CarbonImmutable::now()->subDays(8));
    expect($licence->state()->status)->toBe(LicenceStatus::Grace);

    $result = app(RenewLicence::class)->handle($licence, RenewalTerm::month());
    $fresh = $result->licences[0];

    expect($fresh->status)->toBe(LicenceStatus::Active)
        ->and($fresh->expires_at->toDateTimeString())->toBe(londonEndOfDay('2027-04-10'))
        ->and($fresh->grace_days)->toBe(7)
        ->and($fresh->state()->status)->toBe(LicenceStatus::Active)
        ->and($result->expiresAt->toDateTimeString())->toBe(londonEndOfDay('2027-04-10'));
});

test('a renewal extends a paid period that is still running, so no days are lost', function () {
    $licence = $this->activate($this->firstLicence($this->licensedTenant()));
    app(RenewLicence::class)->handle($licence, RenewalTerm::month(), notify: false);

    $again = app(RenewLicence::class)->handle($licence->fresh(), RenewalTerm::year(), notify: false);

    // Trial ends 17 March → first renewal 17 April 2027 → a year on from there.
    expect($again->licences[0]->expires_at->toDateTimeString())->toBe(londonEndOfDay('2028-04-17'));
});

test('a renewal during the trial counts from the trial end', function () {
    $licence = $this->activate($this->firstLicence($this->licensedTenant()), CarbonImmutable::now()->subDays(2));

    $result = app(RenewLicence::class)->handle($licence, RenewalTerm::month(), notify: false);

    // Trial ends 15 March (activated 8 March + 7 days) → 15 April.
    expect($result->licences[0]->expires_at->toDateTimeString())->toBe(londonEndOfDay('2027-04-15'));
});

test('renewing until a date runs to the end of that day in London, including summer time', function () {
    $licence = $this->activate($this->firstLicence($this->licensedTenant()));

    $result = app(RenewLicence::class)->handle($licence, RenewalTerm::until(CarbonImmutable::parse('2027-07-31')), notify: false);

    expect($result->licences[0]->expires_at->toDateTimeString())->toBe('2027-07-31 22:59:59');
});

test('a date in the past is refused', function () {
    $licence = $this->activate($this->firstLicence($this->licensedTenant()));

    app(RenewLicence::class)->handle($licence, RenewalTerm::until(CarbonImmutable::parse('2027-03-09')));
})->throws(ValidationException::class, 'after today');

test('expired licences become active again', function () {
    $licence = $this->activate($this->firstLicence($this->licensedTenant()), CarbonImmutable::now()->subDays(30));
    expect($licence->state()->status)->toBe(LicenceStatus::Expired);

    expect(app(RenewLicence::class)->handle($licence, RenewalTerm::month(), notify: false)->licences[0]->status)->toBe(LicenceStatus::Active);
});

test('suspended licences stay suspended and issued ones stay issued, with their new expiry', function () {
    $company = $this->licensedTenant();
    $suspended = $this->activate($this->firstLicence($company));
    app(SuspendLicence::class)->handle($suspended, 'Unpaid');
    $issued = $this->licenceOf($this->registerOf($this->branchOf($company), '02'));

    $a = app(RenewLicence::class)->handle($suspended->fresh(), RenewalTerm::month(), notify: false)->licences[0];
    $b = app(RenewLicence::class)->handle($issued, RenewalTerm::month(), notify: false)->licences[0];

    expect($a->status)->toBe(LicenceStatus::Suspended)->and($a->expires_at)->not->toBeNull()
        ->and($b->status)->toBe(LicenceStatus::Issued)->and($b->expires_at->toDateTimeString())->toBe(londonEndOfDay('2027-04-10'));
});

test('the owners are emailed the renewal', function () {
    $company = $this->licensedTenant();
    $second = $this->addMember($company, CompanyRole::Owner);
    $this->addMember($company, CompanyRole::Manager);

    $result = app(RenewLicence::class)->handle($this->firstLicence($company), RenewalTerm::month());

    expect($result->ownersEmailed)->toBe(2);
    Mail::assertQueued(LicenceRenewedMail::class, 2);
    Mail::assertQueued(LicenceRenewedMail::class, fn (LicenceRenewedMail $mail) => $mail->hasTo($second->email)
        && count($mail->data->tills) === 1
        && $mail->data->tills[0]->tillName === 'Till 1'
        && $mail->data->companyId === $company->id);
});

test('no email when asked not to notify', function () {
    app(RenewLicence::class)->handle($this->firstLicence($this->licensedTenant()), RenewalTerm::month(), notify: false);

    Mail::assertNotQueued(LicenceRenewedMail::class);
});

test('renewals are audited with the old and new expiry', function () {
    app(RenewLicence::class)->handle($this->firstLicence($this->licensedTenant()), RenewalTerm::year(), notify: false);

    $entry = AuditLog::query()->where('action', 'licence.renewed')->sole();

    expect($entry->before['expires_at'])->toBeNull()
        ->and($entry->after['expires_at'])->toStartWith('2028-03-10T23:59:59')
        ->and($entry->meta)->toBe(['term' => 'year']);
});

test('renew all moves every active till to one shared expiry and sends one email per owner', function () {
    $company = $this->licensedTenant(tills: 3);
    $branch = $this->branchOf($company);
    $first = $this->activate($this->licenceOf($this->registerOf($branch, '01')));
    app(RenewLicence::class)->handle($first, RenewalTerm::until(CarbonImmutable::parse('2027-05-20')), notify: false);
    app(DeactivateRegister::class)->handle($this->registerOf($branch, '03'));
    Mail::fake();

    $result = app(RenewCompanyLicences::class)->handle($company, RenewalTerm::month());

    expect($result)->toHaveCount(2)
        ->and($result->expiresAt->toDateTimeString())->toBe(londonEndOfDay('2027-06-20'))
        ->and(collect($result->licences)->map->expires_at->map->toDateTimeString()->unique()->all())->toBe([londonEndOfDay('2027-06-20')])
        ->and($this->licenceOf($this->registerOf($branch, '03'))->expires_at)->toBeNull();

    Mail::assertQueued(LicenceRenewedMail::class, 1);
    Mail::assertQueued(LicenceRenewedMail::class, fn (LicenceRenewedMail $mail) => count($mail->data->tills) === 2);
});

test('renew all skips revoked licences and refuses when nothing is left', function () {
    $company = $this->licensedTenant(tills: 1);
    app(RevokeLicence::class)->handle($this->firstLicence($company), 'Closed till');

    app(RenewCompanyLicences::class)->handle($company, RenewalTerm::month());
})->throws(ValidationException::class, 'no active tills');
