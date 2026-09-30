<?php

use App\Domain\PortalUsers\Actions\DeleteOwnAccount;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Validation\ValidationException;
use Tests\Feature\PortalUsers\PortalUsersHelpers as H;

/*
 * Module 4.1: a portal user manages their own profile and password (audited), and a last owner cannot delete their account.
 */

beforeEach(function () {
    $this->company = Company::factory()->create(['name' => 'Khan Mini Mart']);
    $this->user = H::member($this->company, CompanyRole::Manager, attributes: ['name' => 'Bilal', 'email' => 'bilal@example.test']);
});

test('updating the profile is audited in the current business, and a new email must be verified again', function () {
    $this->actingAs($this->user)->patch('/settings/profile', ['name' => 'Bilal Ahmed', 'email' => 'bilal.ahmed@example.test'])
        ->assertSessionHasNoErrors()->assertRedirect('/settings/profile');

    $this->user->refresh();
    expect($this->user->name)->toBe('Bilal Ahmed')->and($this->user->email_verified_at)->toBeNull();

    $audit = AuditLog::query()->where('action', 'user.profile_updated')->sole();
    expect($audit->company_id)->toBe($this->company->id)
        ->and($audit->before)->toBe(['name' => 'Bilal', 'email' => 'bilal@example.test'])
        ->and($audit->after)->toBe(['name' => 'Bilal Ahmed', 'email' => 'bilal.ahmed@example.test']);
});

test('saving an unchanged profile writes no audit entry', function () {
    $this->actingAs($this->user)->patch('/settings/profile', ['name' => 'Bilal', 'email' => 'bilal@example.test'])->assertSessionHasNoErrors();

    expect(AuditLog::query()->where('action', 'user.profile_updated')->count())->toBe(0);
});

test('changing the password is audited without the password', function () {
    $this->actingAs($this->user)->put('/settings/password', [
        'current_password' => 'password',
        'password' => 'a-New-Str0ng-pass!',
        'password_confirmation' => 'a-New-Str0ng-pass!',
    ])->assertSessionHasNoErrors();

    $audit = AuditLog::query()->where('action', 'user.password_changed')->sole();
    expect(json_encode($audit->toArray()))->not->toContain('a-New-Str0ng-pass!')
        ->and($audit->company_id)->toBe($this->company->id);
});

test('the last owner of a business cannot delete their account; with a co-owner they can', function () {
    $owner = H::member($this->company, CompanyRole::Owner);

    $this->actingAs($owner)->from('/settings/profile')->delete('/settings/profile', ['password' => 'password'])
        ->assertSessionHasErrors(['password' => 'You are the only owner of Khan Mini Mart. Make someone else an owner before you delete your account.']);
    expect($owner->fresh())->not->toBeNull();

    H::member($this->company, CompanyRole::Owner);
    app(DeleteOwnAccount::class)->handle($owner);

    expect($owner->fresh())->toBeNull()
        ->and(AuditLog::query()->where('action', 'user.account_deleted')->where('company_id', $this->company->id)->count())->toBe(1);
});

test('owning a cancelled business does not block deleting the account', function () {
    $cancelled = Company::factory()->create(['status' => 'cancelled']);
    $cancelled->users()->attach($this->user->id, ['role' => 'owner', 'is_active' => true]);

    app(DeleteOwnAccount::class)->handle($this->user);

    expect($this->user->fresh())->toBeNull();
});

test('a manager deletes their own account; the business keeps its owner', function () {
    H::member($this->company, CompanyRole::Owner);

    expect(fn () => app(DeleteOwnAccount::class)->handle($this->user))->not->toThrow(ValidationException::class)
        ->and($this->user->fresh())->toBeNull();
});
