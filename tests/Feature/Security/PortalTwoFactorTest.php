<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Security\Actions\SetCompanyTwoFactorRequirement;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\PortalUsers\PortalUsersHelpers as H;
use Tests\Feature\Security\TwoFactorHelpers as TF;

/*
 * Two-factor sign-in for portal users: optional (Settings → Security) unless the business requires it.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->company = Company::factory()->create(['name' => 'Khan Mini Mart', 'status' => 'active']);
    $this->owner = H::member($this->company, CompanyRole::Owner, attributes: ['email' => 'owner@khan.test']);
    $this->manager = H::member($this->company, CompanyRole::Manager, attributes: ['email' => 'manager@khan.test']);
});

function portalSignIn(object $test, string $email): void
{
    $test->post('/login', ['email' => $email, 'password' => 'password'])->assertRedirect(route('app.dashboard', absolute: false));
}

test('two-factor is optional: a user without it signs in with the password only', function () {
    portalSignIn($this, 'manager@khan.test');

    $this->get('/app')->assertOk();
    $this->get('/settings/security')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/security')
        ->where('twoFactor.enabled', false)
        ->where('company.requireTwoFactor', false)
        ->where('company.canManage', false));
});

test('a user sets up two-factor from Settings and it is audited for their business', function () {
    portalSignIn($this, 'manager@khan.test');
    $this->get('/app')->assertOk();

    $secret = null;
    $this->get('/two-factor/setup?from=settings')->assertOk()->assertInertia(function (Assert $page) use (&$secret) {
        $page->component('auth/two-factor-setup')->where('staff', false)->where('required', false)
            ->where('continueUrl', route('security.edit'))->where('cancelUrl', route('security.edit'));
        $secret = str_replace(' ', '', $page->toArray()['props']['setup']['secret']);
    });

    $this->postJson('/two-factor/setup', ['code' => TF::code($secret)])->assertOk()->assertJsonCount(10, 'recoveryCodes');

    expect($this->manager->fresh()->hasTwoFactorEnabled())->toBeTrue();
    $entry = AuditLog::query()->where('action', 'two_factor.enabled')->sole();
    expect($entry->company_id)->toBe($this->company->id)->and($entry->actor_id)->toBe((string) $this->manager->id);

    $this->get('/settings/security')->assertInertia(fn (Assert $page) => $page->where('twoFactor.enabled', true)->where('twoFactor.recoveryCodesLeft', 10));
});

test('a user with two-factor enters a code after the password', function () {
    ['secret' => $secret] = TF::enable($this->manager);
    portalSignIn($this, 'manager@khan.test');

    $this->get('/app')->assertRedirect(route('two-factor.challenge'));
    $this->get('/settings/profile')->assertRedirect(route('two-factor.challenge'));
    $this->get('/two-factor/challenge')->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/two-factor-challenge')->where('staff', false));

    $this->post('/two-factor/challenge', ['code' => TF::wrongCode($secret)])->assertSessionHasErrors('code');
    $this->post('/two-factor/challenge', ['code' => TF::code($secret)])->assertRedirect('/settings/profile');
    $this->get('/app')->assertOk();
});

test('when the business requires two-factor, users without it must set it up first', function () {
    $this->company->forceFill(['require_two_factor' => true])->save();
    portalSignIn($this, 'manager@khan.test');

    $this->get('/app')->assertRedirect(route('two-factor.setup'));
    $this->get('/app/activity')->assertRedirect(route('two-factor.setup'));
    $this->get('/two-factor/setup')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('required', true)->where('requiredBy', 'Khan Mini Mart')->where('cancelUrl', null));
});

test('the owner requires two-factor for the business once their own is on; audited', function () {
    $this->actingAs($this->owner)->put('/settings/security/company', ['requireTwoFactor' => true])->assertSessionHasErrors('requireTwoFactor');
    expect($this->company->fresh()->require_two_factor)->toBeFalse();

    TF::enable($this->owner);
    $this->actingAs($this->owner->fresh())->put('/settings/security/company', ['requireTwoFactor' => true])->assertSessionHas('success');

    expect($this->company->fresh()->require_two_factor)->toBeTrue();
    $entry = AuditLog::query()->where('action', 'company.two_factor_requirement_changed')->sole();
    expect($entry->company_id)->toBe($this->company->id)->and($entry->before)->toBe(['requireTwoFactor' => false])->and($entry->after)->toBe(['requireTwoFactor' => true]);

    $this->put('/settings/security/company', ['requireTwoFactor' => false])->assertSessionHas('success');
    expect($this->company->fresh()->require_two_factor)->toBeFalse();
});

test('only the owner can change the business requirement', function () {
    TF::enable($this->manager);

    $this->actingAs($this->manager->fresh())->put('/settings/security/company', ['requireTwoFactor' => true])->assertForbidden();
    expect($this->company->fresh()->require_two_factor)->toBeFalse();
});

test('one business requiring two-factor does not affect another business', function () {
    $other = Company::factory()->create(['status' => 'active']);
    H::member($other, CompanyRole::Owner, attributes: ['email' => 'owner@other.test']);
    $this->company->forceFill(['require_two_factor' => true])->save();

    portalSignIn($this, 'owner@other.test');
    $this->get('/app')->assertOk();
});

test('a user turns two-factor off with their password, unless the business requires it', function () {
    TF::enable($this->manager);
    $user = $this->manager->fresh();

    $this->actingAs($user)->delete('/settings/security/two-factor', ['password' => 'wrong'])->assertSessionHasErrors('password');
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->company->forceFill(['require_two_factor' => true])->save();
    $this->delete('/settings/security/two-factor', ['password' => 'password'])->assertSessionHasErrors('password');
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->company->forceFill(['require_two_factor' => false])->save();
    $this->delete('/settings/security/two-factor', ['password' => 'password'])->assertSessionHas('success');
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();

    $entry = AuditLog::query()->where('action', 'two_factor.disabled')->sole();
    expect($entry->company_id)->toBe($this->company->id);
});

test('a user makes new recovery codes with their password', function () {
    TF::enable($this->manager);

    $this->actingAs($this->manager->fresh())->postJson('/settings/security/recovery-codes', ['password' => 'nope'])->assertUnprocessable();
    $this->postJson('/settings/security/recovery-codes', ['password' => 'password'])->assertOk()->assertJsonCount(10, 'recoveryCodes');

    expect(AuditLog::query()->where('action', 'two_factor.recovery_codes_regenerated')->where('company_id', $this->company->id)->count())->toBe(1);
});

test('the set-up cannot overwrite an existing secret', function () {
    TF::enable($this->manager);
    $this->actingAs($this->manager->fresh());

    $this->get('/two-factor/setup')->assertRedirect(route('app.dashboard'));
    $this->postJson('/two-factor/setup', ['code' => '123456'])->assertUnprocessable();
});

test('guests are sent to the sign-in from the portal two-factor pages', function () {
    $this->get('/two-factor/challenge')->assertRedirect(route('login'));
    $this->get('/two-factor/setup')->assertRedirect(route('login'));
    $this->get('/settings/security')->assertRedirect(route('login'));
    $this->postJson('/two-factor/setup', ['code' => '123456'])->assertUnauthorized();
});

test('an admin viewing as a customer cannot change the customer\'s two-factor', function () {
    $admin = Admin::factory()->create(['role' => AdminRole::Support]);
    TF::enable($this->manager);
    $this->actingAs($admin, 'admin')
        ->post("/admin/tenants/{$this->company->id}/impersonate", ['user_id' => $this->owner->id])
        ->assertRedirect(route('app.dashboard'));

    // The customer's own second factor is not asked of the admin (the admin passed theirs).
    $this->get('/app')->assertOk();
    $this->get('/two-factor/setup')->assertForbidden();
    $this->postJson('/two-factor/setup', ['code' => '123456'])->assertForbidden();
    $this->delete('/settings/security/two-factor', ['password' => 'password'])->assertForbidden();
    $this->postJson('/settings/security/recovery-codes', ['password' => 'password'])->assertForbidden();
    $this->put('/settings/security/company', ['requireTwoFactor' => true])->assertForbidden();
});

test('SetCompanyTwoFactorRequirement refuses an owner without two-factor', function () {
    expect(fn () => app(SetCompanyTwoFactorRequirement::class)->handle($this->company, true, User::find($this->owner->id)))
        ->toThrow(ValidationException::class);
});

test('support resets a portal user\'s two-factor from the tenant page; audited for the business', function () {
    TF::enable($this->manager);
    $support = Admin::factory()->create(['role' => AdminRole::Support]);
    $accounts = Admin::factory()->create(['role' => AdminRole::Accounts]);
    $url = "/admin/tenants/{$this->company->id}/users/{$this->manager->id}/two-factor/reset";

    $this->actingAs($accounts, 'admin')->post($url)->assertForbidden();
    expect($this->manager->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->actingAs($support, 'admin')->post($url)->assertRedirect()->assertSessionHas('success');
    expect($this->manager->fresh()->hasTwoFactorEnabled())->toBeFalse();

    $entry = AuditLog::query()->where('action', 'user.two_factor_reset')->sole();
    expect($entry->company_id)->toBe($this->company->id)->and($entry->actor_id)->toBe($support->id);

    // Not a member of that business: 404, nothing changes.
    $stranger = User::factory()->create();
    TF::enable($stranger);
    $this->post("/admin/tenants/{$this->company->id}/users/{$stranger->id}/two-factor/reset")->assertNotFound();
    expect($stranger->fresh()->hasTwoFactorEnabled())->toBeTrue();
});
