<?php

use App\Domain\Licensing\Actions\ChangeLicencePlan;
use App\Domain\Licensing\Actions\ReissueKey;
use App\Domain\Licensing\Actions\ReleaseDevice;
use App\Domain\Licensing\Actions\RenewLicence;
use App\Domain\Licensing\Actions\RevokeLicence;
use App\Domain\Licensing\Actions\SuspendLicence;
use App\Domain\Licensing\Actions\UnsuspendLicence;
use App\Domain\Licensing\Actions\UpdateLicenceNotes;
use App\Domain\Licensing\Api\Support\DeviceHistory;
use App\Domain\Licensing\Data\RenewalTerm;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class);

beforeEach(fn () => Mail::fake());

test('reissuing gives a new key, kills the old one and frees the PC', function () {
    $company = $this->licensedTenant();
    $register = $this->registerOf($this->branchOf($company), '01');
    Licence::withoutCompanyScope()->live()->where('register_id', $register->id)->sole()->delete();
    $original = $this->issue($register);
    $licence = $this->activate($original->licence);

    $reissued = app(ReissueKey::class)->handle($licence);
    $fresh = $licence->fresh();

    expect($reissued->replacedKey)->toBeTrue()
        ->and($reissued->plainKey())->not->toBe($original->plainKey())
        ->and(LicenceKey::parse($reissued->plainKey())->matches($fresh->key_hash))->toBeTrue()
        ->and(LicenceKey::parse($original->plainKey())->matches($fresh->key_hash))->toBeFalse()
        ->and(Licence::withoutCompanyScope()->whereIn('key_hash', LicenceKey::parse($original->plainKey())->hashCandidates())->exists())->toBeFalse()
        ->and($fresh->key_last4)->toBe(LicenceKey::parse($reissued->plainKey())->last4())
        ->and($fresh->device_id)->toBeNull()
        ->and($fresh->bound_at)->toBeNull()
        ->and($fresh->status)->toBe(LicenceStatus::Trial)
        ->and($fresh->activated_at)->not->toBeNull()
        ->and($fresh->id)->toBe($licence->id);

    $entry = AuditLog::query()->where('action', 'licence.key_reissued')->sole();
    expect($entry->before['key_last4'])->toBe($original->licence->key_last4)
        ->and($entry->before['device_id'])->toBe('PC-0001')
        ->and(json_encode($entry->toArray()))->not->toContain(LicenceKey::parse($reissued->plainKey())->body());
});

test('releasing the PC keeps the key and the dates and marks the install released', function () {
    $licence = $this->activate($this->firstLicence($this->licensedTenant()));
    $hash = $licence->key_hash;

    $result = app(ReleaseDevice::class)->handle($licence);

    expect($result->licence->device_id)->toBeNull()
        ->and($result->licence->device_name)->toBeNull()
        ->and($result->licence->bound_at)->toBeNull()
        ->and($result->licence->key_hash)->toBe($hash)
        ->and($result->licence->status)->toBe(LicenceStatus::Trial)
        ->and(DeviceHistory::wasReleased($result->licence, 'PC-0001'))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'licence.device_released')->sole()->before)->toMatchArray(['device_id' => 'PC-0001', 'device_name' => 'FRONT-TILL']);
});

test('a licence not in use on a PC has nothing to release', function () {
    app(ReleaseDevice::class)->handle($this->firstLicence($this->licensedTenant()));
})->throws(ValidationException::class, 'not in use');

test('suspending needs a reason and locks the licence until it is lifted', function () {
    $licence = $this->activate($this->firstLicence($this->licensedTenant()));

    expect(fn () => app(SuspendLicence::class)->handle($licence, '  '))->toThrow(ValidationException::class, 'reason');

    $suspended = app(SuspendLicence::class)->handle($licence, 'Card fraud check');
    expect($suspended->from)->toBe(LicenceStatus::Trial)
        ->and($suspended->licence->status)->toBe(LicenceStatus::Suspended)
        ->and($suspended->licence->suspended_reason)->toBe('Card fraud check')
        ->and($suspended->licence->suspended_at)->not->toBeNull()
        ->and($suspended->licence->state()->canTrade())->toBeFalse()
        ->and(fn () => app(SuspendLicence::class)->handle($licence, 'Again'))->toThrow(ValidationException::class, 'already suspended');

    $lifted = app(UnsuspendLicence::class)->handle($licence);
    expect($lifted->to)->toBe(LicenceStatus::Trial)
        ->and($lifted->licence->suspended_reason)->toBeNull()
        ->and($lifted->licence->suspended_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'licence.suspended')->sole()->meta)->toBe(['reason' => 'Card fraud check'])
        ->and(AuditLog::query()->where('action', 'licence.unsuspended')->exists())->toBeTrue();
});

test('lifting a suspension restores what the dates say', function () {
    $licence = $this->activate($this->firstLicence($this->licensedTenant()), CarbonImmutable::now()->subDays(8));
    app(SuspendLicence::class)->handle($licence, 'Checking');

    expect(app(UnsuspendLicence::class)->handle($licence)->to)->toBe(LicenceStatus::Grace);

    $issued = $this->registerOf($this->branchOf($company = $this->licensedTenant('Other', 1, 'OTH'), 'OTH'), '01');
    $never = $this->licenceOf($issued);
    app(SuspendLicence::class)->handle($never, 'Checking');
    expect(app(UnsuspendLicence::class)->handle($never)->to)->toBe(LicenceStatus::Issued);
});

test('only suspended licences can be unsuspended', function () {
    app(UnsuspendLicence::class)->handle($this->firstLicence($this->licensedTenant()));
})->throws(ValidationException::class, 'not suspended');

test('revoking is final: nothing can change a revoked licence', function () {
    $licence = $this->activate($this->firstLicence($this->licensedTenant()));

    $revoked = app(RevokeLicence::class)->handle($licence, 'Stolen PC');

    expect($revoked->licence->status)->toBe(LicenceStatus::Revoked)
        ->and($revoked->licence->revoked_reason)->toBe('Stolen PC')
        ->and($revoked->licence->revoked_at)->not->toBeNull()
        ->and($revoked->licence->live_register_id)->toBeNull()
        ->and($revoked->licence->device_id)->toBe('PC-0001')
        ->and($revoked->licence->state()->status)->toBe(LicenceStatus::Revoked);

    $attempts = [
        'revoke' => fn () => app(RevokeLicence::class)->handle($licence, 'Again'),
        'suspend' => fn () => app(SuspendLicence::class)->handle($licence, 'Why'),
        'unsuspend' => fn () => app(UnsuspendLicence::class)->handle($licence),
        'renew' => fn () => app(RenewLicence::class)->handle($licence, RenewalTerm::month()),
        'reissue' => fn () => app(ReissueKey::class)->handle($licence),
        'release' => fn () => app(ReleaseDevice::class)->handle($licence),
        'plan' => fn () => app(ChangeLicencePlan::class)->handle($licence, $this->proPlan()),
    ];

    foreach ($attempts as $name => $attempt) {
        expect($attempt)->toThrow(ValidationException::class);
        expect($licence->fresh()->status)->toBe(LicenceStatus::Revoked, "{$name} changed a revoked licence");
    }
});

test('revoking needs a reason', function () {
    app(RevokeLicence::class)->handle($this->firstLicence($this->licensedTenant()), '');
})->throws(ValidationException::class, 'reason');

test('changing plan copies the new features and grace days', function () {
    $licence = $this->activate($this->firstLicence($this->licensedTenant()));
    $pro = $this->proPlan();

    $result = app(ChangeLicencePlan::class)->handle($licence, $pro);

    expect($result->changed)->toBeTrue()
        ->and($result->licence->plan_id)->toBe($pro->id)
        ->and($result->licence->features->map->value->all())->toBe($pro->featureValues())
        ->and($result->licence->grace_days)->toBe(2)
        ->and(AuditLog::query()->where('action', 'licence.plan_changed')->sole()->meta)->toMatchArray(['from_plan_name' => 'Standard', 'to_plan_name' => 'Pro']);

    expect(app(ChangeLicencePlan::class)->handle($result->licence, $pro)->changed)->toBeFalse();
});

test('a paid licence takes the new plan paid grace days', function () {
    $licence = $this->activate($this->firstLicence($this->licensedTenant()));
    app(RenewLicence::class)->handle($licence, RenewalTerm::month(), notify: false);

    expect(app(ChangeLicencePlan::class)->handle($licence, $this->proPlan())->licence->grace_days)->toBe(10);
});

test('licences cannot move to a plan that is not offered', function () {
    app(ChangeLicencePlan::class)->handle($this->firstLicence($this->licensedTenant()), Plan::factory()->inactive()->create());
})->throws(ValidationException::class, 'not offered');

test('notes are saved, trimmed and audited only when they change', function () {
    $licence = $this->firstLicence($this->licensedTenant());

    app(UpdateLicenceNotes::class)->handle($licence, '  Front counter PC, Windows 11  ');
    app(UpdateLicenceNotes::class)->handle($licence, 'Front counter PC, Windows 11');

    expect($licence->fresh()->notes)->toBe('Front counter PC, Windows 11')
        ->and(AuditLog::query()->where('action', 'licence.notes_updated')->count())->toBe(1);

    app(UpdateLicenceNotes::class)->handle($licence, '');
    expect($licence->fresh()->notes)->toBeNull();
});
