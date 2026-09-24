<?php

use App\Domain\Licensing\Actions\SuspendLicence;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Shared\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class);

beforeEach(fn () => Mail::fake());

test('it moves statuses along their dates and is idempotent', function () {
    $company = $this->licensedTenant(tills: 4);
    $branch = $this->branchOf($company);
    $now = CarbonImmutable::now();

    $trialToGrace = $this->activate($this->licenceOf($this->registerOf($branch, '01')), $now->subDays(8));
    $trialToExpired = $this->activate($this->licenceOf($this->registerOf($branch, '02')), $now->subDays(11));
    $paidToGrace = $this->activate($this->licenceOf($this->registerOf($branch, '03')), $now->subDays(40));
    $paidToGrace->forceFill(['status' => LicenceStatus::Active, 'expires_at' => $now->subDay(), 'grace_days' => 7])->save();
    $untouched = $this->licenceOf($this->registerOf($branch, '04'));

    $this->artisan('licences:refresh')
        ->expectsOutput('Updated 3 licences.')
        ->expectsOutputToContain('trial→grace: 1')
        ->assertSuccessful();

    expect($trialToGrace->fresh()->status)->toBe(LicenceStatus::Grace)
        ->and($trialToExpired->fresh()->status)->toBe(LicenceStatus::Expired)
        ->and($paidToGrace->fresh()->status)->toBe(LicenceStatus::Grace)
        ->and($untouched->fresh()->status)->toBe(LicenceStatus::Issued);

    $audits = AuditLog::query()->where('action', 'licence.status_changed')->count();

    $this->artisan('licences:refresh')->expectsOutput('All licence statuses are up to date.')->assertSuccessful();

    expect(AuditLog::query()->where('action', 'licence.status_changed')->count())->toBe($audits)->toBe(3);
});

test('changes are audited as the system', function () {
    $licence = $this->activate($this->firstLicence($this->licensedTenant(tills: 1)), CarbonImmutable::now()->subDays(8));

    $this->artisan('licences:refresh')->assertSuccessful();

    $entry = AuditLog::query()->where('action', 'licence.status_changed')->sole();
    expect($entry->actor_type)->toBeNull()
        ->and($entry->subject_id)->toBe($licence->id)
        ->and($entry->before)->toBe(['status' => 'trial'])
        ->and($entry->after)->toBe(['status' => 'grace']);
});

test('suspended, revoked and never-activated licences are left alone', function () {
    $company = $this->licensedTenant(tills: 2);
    $suspended = $this->activate($this->firstLicence($company), CarbonImmutable::now()->subDays(30));
    app(SuspendLicence::class)->handle($suspended, 'Unpaid');

    $this->artisan('licences:refresh')->expectsOutput('All licence statuses are up to date.');

    expect($suspended->fresh()->status)->toBe(LicenceStatus::Suspended);
});

test('it is scheduled daily', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'licences:refresh'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('5 0 * * *');
});
