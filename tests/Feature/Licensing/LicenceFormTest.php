<?php

use App\Domain\Licensing\Actions\ExtendActivateBy;
use App\Domain\Licensing\Actions\IssueLicence;
use App\Domain\Licensing\Actions\ReissueKey;
use App\Domain\Licensing\Actions\RevokeLicence;
use App\Domain\Licensing\Actions\UpdateBranchLicence;
use App\Domain\Licensing\Actions\UpdateBranchLimits;
use App\Domain\Licensing\Data\BranchLicenceSettings;
use App\Domain\Licensing\Enums\LicenceLengthUnit;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Signing\Sspos\TokenKind;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\AddBranch;
use App\Domain\Tenancy\Actions\AddRegister;
use App\Domain\Tenancy\Actions\DeactivateBranch;
use App\Domain\Tenancy\Actions\ReactivateBranch;
use App\Domain\Tenancy\Actions\ReactivateRegister;
use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Enums\BusinessType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Module 1.11: the licence form (contract v1.3.1 §17.2 `company` block, §17.15.3, §17.16).
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class);

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    $this->withSigningKey();
});

function settings(array $overrides = []): BranchLicenceSettings
{
    return new BranchLicenceSettings(...array_merge(['maxRegisters' => 2, 'kind' => TokenKind::Trial], $overrides));
}

test('the token carries the full company block and the branch settings', function () {
    [$company, $licence] = $this->keyedTenant();
    $company->forceFill(['business_type' => BusinessType::Newsagent, 'town' => 'Otley', 'postcode' => 'LS21 1AA', 'owner_name' => 'Imran Khan', 'receipt_footer' => 'Thank you', 'phone' => '0113 496 0000', 'vat_number' => 'GB123456789'])->save();
    $branch = $this->branchOf($company);
    $branch->forceFill(['town' => 'Leeds', 'postcode' => 'LS1 6AB', 'receipt_footer' => 'See you soon'])->save();

    app(UpdateBranchLicence::class)->handle($branch, settings([
        'maxRegisters' => 5, 'kind' => TokenKind::Full, 'length' => 1, 'lengthUnit' => LicenceLengthUnit::Years,
        'validFrom' => CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC'), 'features' => [Feature::Promotions, Feature::SecondScreen],
    ]));

    $token = $this->verifyToken($this->activateTill()->assertOk());

    expect($token->payload)->toMatchArray([
        'kind' => 'full',
        'maxRegisters' => 5,
        'features' => ['promotions', 'multi_branch', 'second_screen'],
        'limits' => ['branches' => 10],
        'validFrom' => '2026-10-01T00:00:00Z',
        'expiresAt' => '2027-10-08T00:00:00Z', // 1 year + the plan's 7 paid grace days
    ])->and($token->payload['company'])->toBe([
        'businessType' => 'Newsagent',
        'address' => '12 Kirkgate, Leeds',
        'town' => 'Leeds',
        'postcode' => 'LS1 6AB',
        'phone' => '0113 496 0000',
        'email' => 'hello@khan.test',
        'vatNumber' => 'GB123456789',
        'ownerName' => 'Imran Khan',
        'receiptFooter' => 'See you soon',
    ]);
});

test('maxRegisters is the setting, not the active tills; one branch has no multi_branch or limits', function () {
    [$company] = $this->keyedTenant(tills: 1);
    $company->forceFill(['multi_branch' => false, 'max_branches' => 1])->save();
    $this->allowTills($this->branchOf($company), 4);

    $this->activateTill()->assertOk()
        ->assertJsonPath('licence.maxRegisters', 4)
        ->assertJsonPath('licence.limits', null);
});

test('a trial length from the branch replaces the plan trial on activation', function () {
    [$company, $licence] = $this->keyedTenant();
    app(UpdateBranchLicence::class)->handle($this->branchOf($company), settings(['length' => 30, 'lengthUnit' => LicenceLengthUnit::Days]));

    $this->activateTill()->assertOk()->assertJsonPath('licence.kind', 'trial');

    expect($licence->fresh()->trial_ends_at?->toIso8601String())->toBe('2026-11-04T09:00:00+00:00')
        ->and($licence->fresh()->expires_at)->toBeNull();
});

test('changing the settings gives the till a new token at its next validate; no change gives null', function () {
    [$company, $licence] = $this->keyedTenant();
    $token = $this->activateTill()->assertOk()->json('licenceToken');

    $this->validateTill($licence->id, $token)->assertOk()->assertJsonPath('licenceToken', null);

    app(UpdateBranchLicence::class)->handle($this->branchOf($company), settings(['maxRegisters' => 3]));
    $new = $this->validateTill($licence->id, $token)->assertOk()->json('licenceToken');
    expect($new)->toBeString()->not->toBe($token)
        ->and($this->verifyToken($this->validateTill($licence->id, $token))->payload['maxRegisters'])->toBe(3);

    $this->validateTill($licence->id, $new)->assertOk()->assertJsonPath('licenceToken', null);

    app(UpdateBranchLimits::class)->handle($company, true, 4);
    expect($this->validateTill($licence->id, $new)->json('licenceToken'))->toBeString();
});

test('saving the same settings changes nothing and writes no audit', function () {
    [$company] = $this->keyedTenant();
    $branch = $this->branchOf($company);

    app(UpdateBranchLicence::class)->handle($branch, BranchLicenceSettings::of($branch));

    expect(AuditLog::query()->where('action', 'branch.licence_updated')->count())->toBe(0);
});

test('features and a new term are copied onto every live key of the branch', function () {
    [$company, $licence] = $this->keyedTenant();
    $this->activate($licence);

    app(UpdateBranchLicence::class)->handle($this->branchOf($company), settings([
        'kind' => TokenKind::Full, 'length' => 6, 'lengthUnit' => LicenceLengthUnit::Months, 'features' => [Feature::SecondScreen],
    ]));

    $licence->refresh();
    expect($licence->features->all())->toBe([Feature::SecondScreen])
        ->and($licence->expires_at?->toIso8601String())->toBe('2027-04-05T09:00:00+00:00')
        ->and($licence->status)->toBe(LicenceStatus::Active)
        ->and(AuditLog::query()->where('action', 'branch.licence_updated')->sole()->meta)->toMatchArray(['licences' => 2]);
});

test('settings are validated and cannot allow fewer tills than keys in use', function () {
    [$company] = $this->keyedTenant();
    $branch = $this->branchOf($company);
    $update = app(UpdateBranchLicence::class);

    expect(fn () => $update->handle($branch, settings(['maxRegisters' => 1])))->toThrow(ValidationException::class, '2 tills in use')
        ->and(fn () => $update->handle($branch, settings(['kind' => TokenKind::Full])))->toThrow(ValidationException::class, 'needs a length')
        ->and(fn () => $update->handle($branch, settings(['length' => 11, 'lengthUnit' => LicenceLengthUnit::Years])))->toThrow(ValidationException::class)
        ->and(fn () => $update->handle($branch, settings(['maxRegisters' => 0])))->toThrow(ValidationException::class);
});

test('a key past the tills allowed is refused in IssueLicence, AddRegister and ReactivateRegister', function () {
    $company = $this->licensedTenant(tills: 2);
    $branch = $this->branchOf($company);
    $second = $this->registerOf($branch, '02');

    expect(fn () => app(AddRegister::class)->handle($branch))->toThrow(ValidationException::class, '2 of 2 tills allowed in use');

    // Till 02 without a key, but only 1 till allowed: its key would be the second.
    app(RevokeLicence::class)->handle($this->licenceOf($second), 'Lost');
    $this->allowTills($branch, 1);
    expect(fn () => $this->issue($second))->toThrow(ValidationException::class, '1 of 1 till keys in use');

    $second->forceFill(['is_active' => false])->saveQuietly();
    expect(fn () => app(ReactivateRegister::class)->handle($second))->toThrow(ValidationException::class, '1 of 1 tills allowed');

    $this->allowTills($branch, 2);
    app(ReactivateRegister::class)->handle($second);
    expect($this->issue($second->fresh())->licence->activate_by)->not->toBeNull();
});

test('a branch is refused without multi-branch or past the branches allowed', function () {
    $company = $this->licensedTenant();
    $details = fn (string $code) => new BranchDetails(code: $code, name: "Shop {$code}");

    $company->forceFill(['multi_branch' => false, 'max_branches' => 1])->save();
    expect(fn () => app(AddBranch::class)->handle($company, $details('BFD'), 1))->toThrow(ValidationException::class, 'licensed for one branch');

    app(UpdateBranchLimits::class)->handle($company, true, 2);
    $bradford = app(AddBranch::class)->handle($company->fresh(), $details('BFD'), 1);
    expect(fn () => app(AddBranch::class)->handle($company->fresh(), $details('YRK'), 1))->toThrow(ValidationException::class, '2 of 2 branches allowed');

    app(DeactivateBranch::class)->handle($bradford);
    app(AddBranch::class)->handle($company->fresh(), $details('YRK'), 1);
    expect(fn () => app(ReactivateBranch::class)->handle($bradford))->toThrow(ValidationException::class, '2 of 2 branches allowed')
        ->and(fn () => app(UpdateBranchLimits::class)->handle($company, false, 1))->toThrow(ValidationException::class, 'runs 2 branches')
        ->and(fn () => app(UpdateBranchLimits::class)->handle($company, true, 1))->toThrow(ValidationException::class, 'runs 2 branches');
});

test('a new branch copies the first branch\'s settings with tills allowed = its tills', function () {
    $company = $this->licensedTenant();
    app(UpdateBranchLicence::class)->handle($this->branchOf($company), settings(['features' => [Feature::SecondScreen]]));

    $branch = app(AddBranch::class)->handle($company, new BranchDetails(code: 'BFD', name: 'Bradford'), 3);

    expect($branch->max_registers)->toBe(3)
        ->and($branch->licence_features)->toBe(['second_screen'])
        ->and($this->licenceOf($this->registerOf($branch, '01'))->features->all())->toBe([Feature::SecondScreen]);
});

test('an unused key past its activate-by date is 410 key.expired until staff extend it', function () {
    [, $licence] = $this->keyedTenant();
    expect($licence->activate_by?->toIso8601String())->toBe('2026-11-04T09:00:00+00:00');

    $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00:00', 'UTC'));
    $this->activateTill()->assertStatus(410)->assertJsonPath('code', 'key.expired');

    app(ExtendActivateBy::class)->handle($licence, CarbonImmutable::parse('2026-11-10'));
    expect($licence->fresh()->activate_by?->toIso8601String())->toBe('2026-11-10T23:59:59+00:00')
        ->and(AuditLog::query()->where('action', 'licence.activate_by_changed')->exists())->toBeTrue();

    $this->activateTill()->assertOk();
    expect(fn () => app(ExtendActivateBy::class)->handle($licence->fresh(), CarbonImmutable::parse('2026-12-01')))->toThrow(ValidationException::class, 'already activated');
});

test('a reissued unused key gets a new activate-by window; past dates are refused', function () {
    [, $licence] = $this->keyedTenant();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'));

    expect(fn () => app(ExtendActivateBy::class)->handle($licence, CarbonImmutable::parse('2026-11-19')))->toThrow(ValidationException::class, 'today or a later date');

    app(ReissueKey::class)->handle($licence);
    expect($licence->fresh()->activate_by?->toIso8601String())->toBe('2026-12-20T09:00:00+00:00');
});

test('activate-by days come from config', function () {
    config(['licence.activate_by_days' => 10]);

    expect(IssueLicence::activateBy()->toIso8601String())->toBe('2026-10-15T09:00:00+00:00');
});

test('a new term never shortens a paid expiry and never turns a paid licence back into a trial', function () {
    [$company, $licence] = $this->keyedTenant();
    $this->activate($licence);
    $branch = $this->branchOf($company);

    // Customer paid for a year.
    app(UpdateBranchLicence::class)->handle($branch, settings(['kind' => TokenKind::Full, 'length' => 1, 'lengthUnit' => LicenceLengthUnit::Years]));
    expect($licence->refresh()->expires_at?->toIso8601String())->toBe('2027-10-05T09:00:00+00:00');

    // A shorter full term keeps the paid date.
    app(UpdateBranchLicence::class)->handle($branch->refresh(), settings(['kind' => TokenKind::Full, 'length' => 1, 'lengthUnit' => LicenceLengthUnit::Months]));
    expect($licence->refresh()->expires_at?->toIso8601String())->toBe('2027-10-05T09:00:00+00:00');

    // Switching the branch to trial does not touch a licence that is still paid.
    app(UpdateBranchLicence::class)->handle($branch->refresh(), settings(['kind' => TokenKind::Trial, 'length' => 14, 'lengthUnit' => LicenceLengthUnit::Days]));
    expect($licence->refresh()->expires_at?->toIso8601String())->toBe('2027-10-05T09:00:00+00:00')
        ->and($licence->status)->toBe(LicenceStatus::Active);

    // A longer full term still extends it.
    app(UpdateBranchLicence::class)->handle($branch->refresh(), settings(['kind' => TokenKind::Full, 'length' => 2, 'lengthUnit' => LicenceLengthUnit::Years]));
    expect($licence->refresh()->expires_at?->toIso8601String())->toBe('2028-10-05T09:00:00+00:00');
});
