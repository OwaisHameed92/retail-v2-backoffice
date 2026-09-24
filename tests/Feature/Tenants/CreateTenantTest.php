<?php

use App\Domain\Mail\Mailables\SetPasswordMail;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class);

beforeEach(fn () => Mail::fake());

test('it creates the company, first branch, tills and owner in one go', function () {
    $company = app(CreateTenant::class)->handle($this->newTenant(tills: 3));

    expect($company->status)->toBe(CompanyStatus::Trial)
        ->and($company->trial_ends_at)->toBeNull()
        ->and($company->activated_at)->toBeNull();

    $branch = $this->branchOf($company);
    expect($branch->name)->toBe('Leeds');

    $registers = Register::withoutCompanyScope()->where('branch_id', $branch->id)->orderBy('code')->get();
    expect($registers->pluck('code')->all())->toBe(['01', '02', '03'])
        ->and($registers->pluck('name')->all())->toBe(['Till 1', 'Till 2', 'Till 3'])
        ->and($registers->pluck('is_main_till')->all())->toBe([true, false, false])
        ->and($registers->every(fn (Register $r) => $r->company_id === $company->id))->toBeTrue();

    $owner = User::query()->where('email', 'owner@khan.test')->firstOrFail();
    expect($owner->name)->toBe('Aisha Khan');
    $membership = $company->users()->whereKey($owner->id)->firstOrFail()->getRelation('membership');
    expect($membership->role)->toBe(CompanyRole::Owner)->and($membership->is_active)->toBeTrue();
});

test('portal-made ids are upper-case ULIDs the till accepts', function () {
    $company = app(CreateTenant::class)->handle($this->newTenant());
    $branch = $this->branchOf($company);

    expect(Ulid::isValid($company->id))->toBeTrue()
        ->and(Ulid::isValid($branch->id))->toBeTrue()
        ->and(Ulid::isValid($this->registerOf($branch, '01')->id))->toBeTrue();
});

test('a new owner is emailed the branded set-password link', function () {
    app(CreateTenant::class)->handle($this->newTenant());

    Mail::assertQueued(SetPasswordMail::class, fn (SetPasswordMail $mail) => $mail->hasTo('owner@khan.test'));
    Mail::assertQueuedCount(1);
});

test('an existing user becomes owner without a new password or email', function () {
    $existing = User::factory()->create(['email' => 'owner@khan.test', 'password' => Hash::make('their-own-password')]);

    $company = app(CreateTenant::class)->handle($this->newTenant());

    expect($company->owners()->pluck('users.id')->all())->toBe([$existing->id])
        ->and(Hash::check('their-own-password', $existing->fresh()->password))->toBeTrue();
    Mail::assertNothingQueued();
});

test('owner email is stored in lower case', function () {
    app(CreateTenant::class)->handle($this->newTenant(ownerEmail: '  Owner@KHAN.test '));

    expect(User::query()->where('email', 'owner@khan.test')->exists())->toBeTrue();
});

test('an active customer gets an activation date and no trial end', function () {
    $company = app(CreateTenant::class)->handle($this->newTenant(status: CompanyStatus::Active));

    expect($company->status)->toBe(CompanyStatus::Active)
        ->and($company->activated_at)->not->toBeNull()
        ->and($company->trial_ends_at)->toBeNull();
});

test('the number of tills must be 1 to 20', function (int $tills) {
    app(CreateTenant::class)->handle($this->newTenant(tills: $tills));
})->with([0, 21])->throws(ValidationException::class);

test('a new business cannot start suspended or cancelled', function () {
    app(CreateTenant::class)->handle($this->newTenant(status: CompanyStatus::Suspended));
})->throws(ValidationException::class);

test('nothing is saved when a later step fails', function () {
    expect(fn () => app(CreateTenant::class)->handle($this->newTenant(code: 'l1')))->toThrow(ValidationException::class);

    expect(Company::query()->count())->toBe(0)
        ->and(Branch::withoutCompanyScope()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

test('creating a tenant is audited', function () {
    $company = app(CreateTenant::class)->handle($this->newTenant(tills: 2));

    $actions = AuditLog::query()->where('company_id', $company->id)->pluck('action')->all();

    expect($actions)->toContain('company.created', 'branch.created', 'register.created', 'company.user_added', 'company.user_password_link_sent')
        ->and(array_count_values($actions)['register.created'])->toBe(2);
});
