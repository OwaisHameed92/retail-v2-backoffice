<?php

use App\Domain\Licensing\Actions\IssueLicence;
use App\Domain\Licensing\Actions\RevokeLicence;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Actions\CancelCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class);

beforeEach(fn () => Mail::fake());

function bareTill(?Company $company = null): Register
{
    $company ??= Company::factory()->create();
    $branch = Branch::factory()->forCompany($company)->create(['code' => 'LDS']);

    return Register::factory()->forBranch($branch)->create(['code' => '01', 'name' => 'Till 1', 'is_main_till' => true]);
}

test('it issues a key and stores only its hash and last 4 characters', function () {
    $plan = $this->standardPlan();
    $issued = $this->issue(bareTill(), $plan);
    $licence = $issued->licence->fresh();
    $key = LicenceKey::parse($issued->plainKey());

    expect($issued->plainKey())->toMatch('/^SSP-[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){3}$/')
        ->and($licence->key_hash)->toBe($key->hash())
        ->and($licence->key_last4)->toBe($key->last4())
        ->and($licence->maskedKey())->toBe('SSP-••••-••••-••••-'.$key->last4())
        ->and($this->storedText())->not->toContain($key->body())->not->toContain($issued->plainKey());
});

test('a new licence is issued, not activated, with the plan copied onto it', function () {
    $plan = $this->standardPlan();
    $register = bareTill();

    $licence = $this->issue($register, $plan)->licence->fresh();

    expect($licence->status)->toBe(LicenceStatus::Issued)
        ->and($licence->company_id)->toBe($register->company_id)
        ->and($licence->branch_id)->toBe($register->branch_id)
        ->and($licence->register_id)->toBe($register->id)
        ->and($licence->plan_id)->toBe($plan->id)
        ->and($licence->features->map->value->all())->toBe(['stockControl', 'cashOffice'])
        ->and($licence->grace_days)->toBe(3)
        ->and($licence->activated_at)->toBeNull()
        ->and($licence->expires_at)->toBeNull()
        ->and($licence->device_id)->toBeNull()
        ->and($licence->live_register_id)->toBe($register->id)
        ->and($licence->state()->status)->toBe(LicenceStatus::Issued);
});

test('the features are a snapshot: editing the plan later does not change the licence', function () {
    $plan = $this->standardPlan();
    $licence = $this->issue(bareTill(), $plan)->licence;

    $plan->update(['features' => [Feature::AiAssistant]]);

    expect($licence->fresh()->features->map->value->all())->toBe(['stockControl', 'cashOffice']);
});

test('without a plan it uses the company plan, then the portal default, then the first active plan', function () {
    $standard = $this->standardPlan();
    $pro = $this->proPlan();

    $company = Company::factory()->create();
    $company->forceFill(['plan_id' => $pro->id])->save();
    expect($this->issue(bareTill($company))->licence->plan_id)->toBe($pro->id);

    expect($this->issue(bareTill())->licence->plan_id)->toBe($standard->id);

    config(['licence.default_plan' => 'missing']);
    $first = Plan::factory()->create(['sort_order' => -5]);
    expect($this->issue(bareTill())->licence->plan_id)->toBe($first->id);
});

test('an inactive company plan falls back to the portal default', function () {
    $standard = $this->standardPlan();
    $company = Company::factory()->create();
    $company->forceFill(['plan_id' => Plan::factory()->inactive()->create()->id])->save();

    expect($this->issue(bareTill($company))->licence->plan_id)->toBe($standard->id);
});

test('it refuses when no plan exists', function () {
    $this->issue(bareTill());
})->throws(ValidationException::class, 'Create a plan first');

test('it refuses a plan that is not offered', function (string $state) {
    $this->issue(bareTill(), Plan::factory()->{$state}()->create());
})->with(['inactive', 'archived'])->throws(ValidationException::class, 'not offered');

test('a till has one live licence: a second is refused until the first is revoked', function () {
    $this->standardPlan();
    $register = bareTill();
    $first = $this->issue($register)->licence;

    expect(fn () => $this->issue($register))->toThrow(ValidationException::class, 'already has a licence');

    app(RevokeLicence::class)->handle($first, 'Lost PC');
    $second = $this->issue($register)->licence;

    expect($second->id)->not->toBe($first->id)
        ->and(Licence::withoutCompanyScope()->where('register_id', $register->id)->count())->toBe(2)
        ->and(Licence::withoutCompanyScope()->live()->where('register_id', $register->id)->sole()->id)->toBe($second->id);
});

test('the database itself refuses a second live licence for a till', function () {
    $plan = $this->standardPlan();
    $register = bareTill();
    Licence::factory()->forRegister($register)->onPlan($plan)->create();

    Licence::factory()->forRegister($register)->onPlan($plan)->create();
})->throws(UniqueConstraintViolationException::class);

test('revoked licences do not count towards the one-licence rule in the database', function () {
    $plan = $this->standardPlan();
    $register = bareTill();
    Licence::factory()->forRegister($register)->onPlan($plan)->create(['status' => LicenceStatus::Revoked]);
    Licence::factory()->forRegister($register)->onPlan($plan)->create(['status' => LicenceStatus::Revoked]);

    expect(Licence::factory()->forRegister($register)->onPlan($plan)->create()->live_register_id)->toBe($register->id);
});

test('soft deleting a licence frees its till', function () {
    $this->standardPlan();
    $register = bareTill();
    $this->issue($register)->licence->delete();

    expect($this->issue($register)->licence->live_register_id)->toBe($register->id);
});

test('inactive tills, inactive branches and cancelled businesses get no licence', function () {
    $this->standardPlan();

    $inactiveTill = bareTill();
    $inactiveTill->forceFill(['is_active' => false, 'is_main_till' => false])->save();
    expect(fn () => $this->issue($inactiveTill))->toThrow(ValidationException::class, 'deactivated');

    $inactiveBranch = bareTill();
    Branch::withoutCompanyScope()->whereKey($inactiveBranch->branch_id)->update(['is_active' => false]);
    expect(fn () => $this->issue($inactiveBranch))->toThrow(ValidationException::class, 'inactive');

    $company = $this->licensedTenant('Closed Shop', 1, 'CLS');
    app(CancelCompany::class)->handle($company, 'Closed');
    expect(fn () => $this->issue(Register::withoutCompanyScope()->whereBelongsTo($company)->first()))->toThrow(ValidationException::class, 'cancelled');
});

test('issuing is audited without the key', function () {
    $issued = $this->issue(bareTill(), $this->standardPlan());

    $entry = AuditLog::query()->where('action', 'licence.issued')->sole();

    expect($entry->subject_id)->toBe($issued->licence->id)
        ->and($entry->company_id)->toBe($issued->licence->company_id)
        ->and($entry->after)->toMatchArray(['plan' => 'standard', 'status' => 'issued', 'key_last4' => $issued->licence->key_last4])
        ->and(json_encode($entry->toArray()))->not->toContain(LicenceKey::parse($issued->plainKey())->body());
});

test('the licence id is an upper-case ULID the till accepts', function () {
    $licence = $this->issue(bareTill(), $this->standardPlan())->licence;

    expect(Ulid::isValid($licence->id))->toBeTrue();
});

test('the key hash is hidden from arrays and JSON', function () {
    $licence = $this->issue(bareTill(), $this->standardPlan())->licence;

    expect($licence->toArray())->not->toHaveKey('key_hash');
});

test('the issue action is available from the container', function () {
    expect(app(IssueLicence::class))->toBeInstanceOf(IssueLicence::class);
});
