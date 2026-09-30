<?php

use App\Domain\PortalUsers\Actions\ChangeMemberAccess;
use App\Domain\PortalUsers\Actions\RemoveMember;
use App\Domain\PortalUsers\Actions\SetMemberActive;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Validation\ValidationException;
use Tests\Feature\PortalUsers\PortalUsersHelpers as H;

/*
 * Module 4.1: ChangeMemberAccess, SetMemberActive, RemoveMember — last-owner guard, "not yourself", one-shop rules.
 */

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->owner = H::member($this->company, CompanyRole::Owner);
    $this->manager = H::member($this->company, CompanyRole::Manager);
    $this->shop = Branch::factory()->forCompany($this->company)->create();
});

test('changing a role and shop is saved and audited with before and after', function () {
    app(ChangeMemberAccess::class)->handle($this->company, $this->manager, CompanyRole::Staff, $this->shop->id, $this->owner);

    expect(H::membership($this->company, $this->manager))->role->toBe('staff')->branch_id->toBe($this->shop->id);

    $audit = AuditLog::query()->where('action', 'company.user_access_changed')->sole();
    expect($audit->before)->toBe(['role' => 'manager', 'branch_id' => null])
        ->and($audit->after)->toBe(['role' => 'staff', 'branch_id' => $this->shop->id])
        ->and($audit->company_id)->toBe($this->company->id);

    // Back to every shop.
    app(ChangeMemberAccess::class)->handle($this->company, $this->manager, CompanyRole::Staff, null, $this->owner);
    expect(H::membership($this->company, $this->manager)->branch_id)->toBeNull();
});

test('an owner is never limited to a shop, and the shop must be an open shop of this business', function () {
    $foreign = Branch::factory()->forCompany(Company::factory()->create())->create();

    expect(fn () => app(ChangeMemberAccess::class)->handle($this->company, $this->manager, CompanyRole::Owner, $this->shop->id, $this->owner))
        ->toThrow(ValidationException::class, 'every shop')
        ->and(fn () => app(ChangeMemberAccess::class)->handle($this->company, $this->manager, CompanyRole::Manager, $foreign->id, $this->owner))
        ->toThrow(ValidationException::class, 'open shops')
        ->and(H::membership($this->company, $this->manager))->role->toBe('manager')->branch_id->toBeNull();
});

test('the last owner can never be demoted, deactivated or removed', function () {
    $second = H::member($this->company, CompanyRole::Manager);

    expect(fn () => app(ChangeMemberAccess::class)->handle($this->company, $this->owner, CompanyRole::Manager, null, $second))
        ->toThrow(ValidationException::class, 'only owner')
        ->and(fn () => app(SetMemberActive::class)->handle($this->company, $this->owner, false, $second))
        ->toThrow(ValidationException::class, 'only owner')
        ->and(fn () => app(RemoveMember::class)->handle($this->company, $this->owner, $second))
        ->toThrow(ValidationException::class, 'only owner')
        ->and(H::membership($this->company, $this->owner))->role->toBe('owner')->is_active->toBeTruthy();
});

test('with a second active owner, an owner can be demoted', function () {
    $coOwner = H::member($this->company, CompanyRole::Owner);
    H::member($this->company, CompanyRole::Owner, active: false); // a deactivated owner does not count

    app(ChangeMemberAccess::class)->handle($this->company, $coOwner, CompanyRole::Manager, null, $this->owner);

    expect(H::membership($this->company, $coOwner)->role)->toBe('manager')
        ->and(fn () => app(ChangeMemberAccess::class)->handle($this->company, $this->owner, CompanyRole::Manager, null, $coOwner))
        ->toThrow(ValidationException::class, 'only owner');
});

test('nobody changes, deactivates or removes themselves', function () {
    $coOwner = H::member($this->company, CompanyRole::Owner);

    expect(fn () => app(ChangeMemberAccess::class)->handle($this->company, $coOwner, CompanyRole::Manager, null, $coOwner))->toThrow(ValidationException::class, 'your own role')
        ->and(fn () => app(SetMemberActive::class)->handle($this->company, $coOwner, false, $coOwner))->toThrow(ValidationException::class, 'deactivate yourself')
        ->and(fn () => app(RemoveMember::class)->handle($this->company, $coOwner, $coOwner))->toThrow(ValidationException::class, 'remove yourself');
});

test('a deactivated user keeps their role but cannot open the portal until reactivated', function () {
    app(SetMemberActive::class)->handle($this->company, $this->manager, false, $this->owner);

    expect(H::membership($this->company, $this->manager))->is_active->toBeFalsy()->role->toBe('manager');
    $this->actingAs($this->manager)->get('/app')->assertRedirect('/login');

    app(SetMemberActive::class)->handle($this->company, $this->manager, true, $this->owner);
    $this->actingAs($this->manager->fresh())->get('/app')->assertOk();

    expect(AuditLog::query()->whereIn('action', ['company.user_deactivated', 'company.user_reactivated'])->count())->toBe(2);
});

test('removing a user detaches them from this business only and is audited', function () {
    $elsewhere = Company::factory()->create();
    $elsewhere->users()->attach($this->manager->id, ['role' => 'owner', 'is_active' => true]);

    app(RemoveMember::class)->handle($this->company, $this->manager, $this->owner);

    expect(H::membership($this->company, $this->manager))->toBeNull()
        ->and(H::membership($elsewhere, $this->manager))->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'company.user_removed')->where('company_id', $this->company->id)->count())->toBe(1);
});

test('the actions refuse someone who is not a user of this business', function () {
    $outsider = H::member(Company::factory()->create(), CompanyRole::Staff);

    expect(fn () => app(ChangeMemberAccess::class)->handle($this->company, $outsider, CompanyRole::Manager, null, $this->owner))->toThrow(ValidationException::class, 'is not a user')
        ->and(fn () => app(SetMemberActive::class)->handle($this->company, $outsider, false, $this->owner))->toThrow(ValidationException::class, 'is not a user');
});
