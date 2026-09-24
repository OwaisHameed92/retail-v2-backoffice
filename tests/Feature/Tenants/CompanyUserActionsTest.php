<?php

use App\Domain\Mail\Mailables\SetPasswordMail;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\AddCompanyUser;
use App\Domain\Tenancy\Actions\ChangeCompanyUserRole;
use App\Domain\Tenancy\Actions\RemoveCompanyUser;
use App\Domain\Tenancy\Actions\ResendPasswordSetupLink;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class);

beforeEach(fn () => Mail::fake());

test('adding a new person creates their login and emails a set-password link', function () {
    $company = $this->tenant();

    $user = app(AddCompanyUser::class)->handle($company, 'Sam Patel', 'Sam@Khan.test', CompanyRole::Manager);

    expect($user->email)->toBe('sam@khan.test')
        ->and($company->users()->whereKey($user->id)->first()?->getRelation('membership')->role)->toBe(CompanyRole::Manager);
    Mail::assertQueued(SetPasswordMail::class, fn (SetPasswordMail $mail) => $mail->hasTo('sam@khan.test'));
    $entry = AuditLog::query()->where('action', 'company.user_added')->where('subject_id', (string) $user->id)->firstOrFail();
    expect($entry->company_id)->toBe($company->id)->and($entry->meta['new_account'])->toBeTrue();
});

test('adding an existing login attaches it without an email', function () {
    $company = $this->tenant();
    $existing = User::factory()->create(['email' => 'sam@khan.test']);

    app(AddCompanyUser::class)->handle($company, 'Ignored', 'sam@khan.test', CompanyRole::Staff);

    expect($company->users()->whereKey($existing->id)->exists())->toBeTrue()
        ->and($existing->fresh()->name)->not->toBe('Ignored');
    Mail::assertNotQueued(SetPasswordMail::class, fn (SetPasswordMail $mail) => $mail->hasTo('sam@khan.test'));
});

test('adding someone who is already an active member is refused', function () {
    $company = $this->tenant(ownerEmail: 'owner@khan.test');

    app(AddCompanyUser::class)->handle($company, 'Owner', 'owner@khan.test', CompanyRole::Staff);
})->throws(ValidationException::class, 'already a user');

test('an inactive membership is reactivated with the new role', function () {
    $company = $this->tenant();
    $user = $this->addMember($company, CompanyRole::Staff, active: false);

    app(AddCompanyUser::class)->handle($company, $user->name, $user->email, CompanyRole::Accountant);

    $membership = $company->users()->whereKey($user->id)->firstOrFail()->getRelation('membership');
    expect($membership->is_active)->toBeTrue()->and($membership->role)->toBe(CompanyRole::Accountant);
});

test('roles can be changed and the change is audited', function () {
    $company = $this->tenant();
    $user = $this->addMember($company, CompanyRole::Staff);

    app(ChangeCompanyUserRole::class)->handle($company, $user, CompanyRole::Manager);

    expect($company->users()->whereKey($user->id)->firstOrFail()->getRelation('membership')->role)->toBe(CompanyRole::Manager);
    $entry = AuditLog::query()->where('action', 'company.user_role_changed')->firstOrFail();
    expect($entry->before)->toBe(['role' => 'staff'])->and($entry->after)->toBe(['role' => 'manager']);
});

test('the last active owner cannot be demoted', function () {
    $company = $this->tenant();

    app(ChangeCompanyUserRole::class)->handle($company, $this->ownerOf($company), CompanyRole::Manager);
})->throws(ValidationException::class, 'only owner');

test('an owner can be demoted once another active owner exists', function () {
    $company = $this->tenant();
    $owner = $this->ownerOf($company);
    $this->addMember($company, CompanyRole::Owner);

    app(ChangeCompanyUserRole::class)->handle($company, $owner, CompanyRole::Manager);

    expect($company->owners()->count())->toBe(1);
});

test('an inactive owner does not count as another owner', function () {
    $company = $this->tenant();
    $this->addMember($company, CompanyRole::Owner, active: false);

    app(RemoveCompanyUser::class)->handle($company, $this->ownerOf($company));
})->throws(ValidationException::class, 'only owner');

test('members can be removed, the account stays', function () {
    $company = $this->tenant();
    $user = $this->addMember($company, CompanyRole::Staff);

    app(RemoveCompanyUser::class)->handle($company, $user);

    expect($company->users()->whereKey($user->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'company.user_removed')->exists())->toBeTrue();
});

test('the last active owner cannot be removed', function () {
    $company = $this->tenant();

    app(RemoveCompanyUser::class)->handle($company, $this->ownerOf($company));
})->throws(ValidationException::class);

test('changing or removing a non-member is refused', function () {
    $company = $this->tenant();
    $stranger = User::factory()->create();

    expect(fn () => app(ChangeCompanyUserRole::class)->handle($company, $stranger, CompanyRole::Staff))->toThrow(ValidationException::class)
        ->and(fn () => app(RemoveCompanyUser::class)->handle($company, $stranger))->toThrow(ValidationException::class);
});

test('a set-password link can be sent again and is audited without the token', function () {
    $company = $this->tenant();
    $owner = $this->ownerOf($company);
    Mail::fake();

    app(ResendPasswordSetupLink::class)->handle($owner, $company);

    Mail::assertQueued(SetPasswordMail::class, fn (SetPasswordMail $mail) => $mail->hasTo($owner->email));
    $entry = AuditLog::query()->where('action', 'company.user_password_link_sent')->latest('created_at')->firstOrFail();
    expect(json_encode($entry->toArray()))->not->toContain('token');
});
