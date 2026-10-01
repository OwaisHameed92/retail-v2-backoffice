<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\CancelCompany;
use App\Domain\Tenancy\Actions\ImpersonateCompanyUser;
use App\Domain\Tenancy\Actions\SuspendCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Support\Impersonation;
use App\Http\Middleware\BlockAdminWhileImpersonating;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->adminUser = $this->admin(AdminRole::Support);
    $this->company = $this->tenant('Khan Mini Mart', ownerEmail: 'owner@khan.test');
    $this->owner = $this->ownerOf($this->company);
    Auth::guard('admin')->login($this->adminUser);
    $this->passTwoFactorFor($this->adminUser, 'admin');
});

function startImpersonating(object $test, ?User $user = null): void
{
    $test->post("/admin/tenants/{$test->company->id}/impersonate", ['user_id' => ($user ?? $test->owner)->id])
        ->assertRedirect(route('app.dashboard'));
}

test('an admin can open the portal as a company user', function () {
    startImpersonating($this);

    expect(Auth::guard('web')->id())->toBe($this->owner->id)
        ->and(Auth::guard('admin')->id())->toBe($this->adminUser->id)
        ->and(session('impersonation.company_id'))->toBe($this->company->id)
        ->and(session('current_company_id'))->toBe($this->company->id);

    $entry = AuditLog::query()->where('action', 'company.impersonation_started')->firstOrFail();
    expect($entry->actor_id)->toBe($this->adminUser->id)
        ->and($entry->company_id)->toBe($this->company->id)
        ->and($entry->meta['user_email'])->toBe('owner@khan.test');
});

test('the portal shows the viewing-as banner data', function () {
    startImpersonating($this);

    $this->get('/app')->assertOk()->assertInertia(fn ($page) => $page
        ->where('impersonation.companyName', 'Khan Mini Mart')
        ->where('impersonation.userEmail', 'owner@khan.test')
        ->where('impersonation.adminName', $this->adminUser->name)
        ->where('company.id', $this->company->id));
});

test('the admin area is blocked while impersonating', function () {
    startImpersonating($this);

    $this->get('/admin')->assertRedirect(route('app.dashboard'))->assertSessionHas('error', BlockAdminWhileImpersonating::MESSAGE);
    $this->get("/admin/tenants/{$this->company->id}")->assertRedirect(route('app.dashboard'));
    $this->post("/admin/tenants/{$this->company->id}/impersonate", ['user_id' => $this->owner->id])->assertRedirect(route('app.dashboard'));
});

test('impersonation cannot be nested', function () {
    startImpersonating($this);

    app(ImpersonateCompanyUser::class)->handle($this->adminUser, $this->company, $this->owner, session()->driver());
})->throws(ValidationException::class, 'already viewing');

test('return to admin restores the admin session and is audited', function () {
    startImpersonating($this);
    $rememberToken = $this->owner->fresh()->remember_token;

    $this->post('/admin/impersonation/stop')
        ->assertRedirect(route('admin.tenants.show', $this->company))
        ->assertSessionHas('success');

    expect(Auth::guard('web')->check())->toBeFalse()
        ->and(Auth::guard('admin')->id())->toBe($this->adminUser->id)
        ->and(session()->has(Impersonation::SESSION_KEY))->toBeFalse()
        ->and(session()->has('current_company_id'))->toBeFalse()
        ->and($this->owner->fresh()->remember_token)->toBe($rememberToken)
        ->and(AuditLog::query()->where('action', 'company.impersonation_ended')->where('actor_id', $this->adminUser->id)->exists())->toBeTrue();

    $this->get("/admin/tenants/{$this->company->id}")->assertOk();
});

test('stop without an impersonation just goes to the dashboard', function () {
    $this->post('/admin/impersonation/stop')->assertRedirect(route('admin.dashboard'));
});

test('accounts staff cannot log in as a customer', function () {
    Auth::guard('admin')->login($accounts = $this->admin(AdminRole::Accounts));
    $this->passTwoFactorFor($accounts, 'admin');

    $this->post("/admin/tenants/{$this->company->id}/impersonate", ['user_id' => $this->owner->id])->assertForbidden();
    expect(Auth::guard('web')->check())->toBeFalse();
});

test('only active members of that company can be impersonated', function () {
    $other = $this->tenant('Other', code: 'OTH');
    $inactive = $this->addMember($this->company, CompanyRole::Staff, active: false);

    $this->post("/admin/tenants/{$this->company->id}/impersonate", ['user_id' => $this->ownerOf($other)->id])->assertNotFound();
    $this->post("/admin/tenants/{$this->company->id}/impersonate", ['user_id' => $inactive->id])->assertSessionHasErrors('user_id');
    expect(Auth::guard('web')->check())->toBeFalse();
});

test('a cancelled company cannot be impersonated', function () {
    app(CancelCompany::class)->handle($this->company, 'Closed');

    $this->post("/admin/tenants/{$this->company->id}/impersonate", ['user_id' => $this->owner->id])->assertSessionHasErrors('user_id');
});

test('an admin viewing a suspended company sees the portal, not the hold page', function () {
    app(SuspendCompany::class)->handle($this->company, 'Unpaid');
    startImpersonating($this);

    $this->get('/app')->assertOk()->assertInertia(fn ($page) => $page->component('app/dashboard'));
});

test('account settings cannot be changed while impersonating', function () {
    startImpersonating($this);
    Auth::shouldUse('web'); // the test app is long-lived: undo auth:admin from the previous request

    $this->put('/settings/password', ['current_password' => 'password', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123'])
        ->assertForbidden();
    $this->patch('/settings/profile', ['name' => 'Hacked', 'email' => 'owner@khan.test'])->assertForbidden();
    expect($this->owner->fresh()->name)->not->toBe('Hacked');
});

test('impersonation ends if the admin is signed out or loses access', function () {
    startImpersonating($this);

    $this->adminUser->update(['is_active' => false]);
    Auth::forgetGuards();

    $this->get('/app')->assertRedirect(route('admin.login'));
    expect(session()->has(Impersonation::SESSION_KEY))->toBeFalse()
        ->and(Auth::guard('web')->check())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'company.impersonation_ended')->firstOrFail()->meta['reason'])->toBe('admin session ended');
});

test('the company being cancelled mid-session sends the admin back', function () {
    startImpersonating($this);
    $this->company->forceFill(['status' => 'cancelled'])->save();

    $this->get('/app')->assertRedirect(route('admin.tenants.show', $this->company->id));
    expect(Auth::guard('web')->check())->toBeFalse()->and(Auth::guard('admin')->check())->toBeTrue();
});

test('customers never get an impersonation banner', function () {
    Auth::guard('admin')->logout();

    $this->actingAs($this->owner)->get('/app')->assertInertia(fn ($page) => $page->where('impersonation', null));
});

test('security review M5: the customer view ends by itself after the configured minutes, and that is audited', function () {
    config(['security.impersonation_minutes' => 30]);
    startImpersonating($this);
    $this->get('/app')->assertOk();

    $this->travel(31)->minutes();
    $this->get('/app')->assertRedirect(route('admin.tenants.show', $this->company->id));

    expect(Impersonation::active(session()->driver()))->toBeFalse()
        ->and(Auth::guard('web')->check())->toBeFalse()
        ->and(Auth::guard('admin')->id())->toBe($this->adminUser->id)
        ->and(AuditLog::query()->where('action', 'company.impersonation_ended')->sole()->meta['reason'])->toBe('expired');
});

test('security review M5: the customer view is pinned to its business; switching business is refused', function () {
    $other = $this->tenant('Corner Shop', ownerEmail: 'boss@corner.test');
    $other->users()->attach($this->owner->id, ['role' => CompanyRole::Owner->value, 'is_active' => true]);
    startImpersonating($this);

    $this->post(route('app.company.switch'), ['company_id' => $other->id])->assertForbidden();

    // Even with the session pointing elsewhere, the portal shows the business the view was started for.
    $this->withSession(['current_company_id' => $other->id])->get('/app')->assertOk()
        ->assertInertia(fn ($page) => $page->where('company.id', $this->company->id));

    // Once the user leaves that business, the view ends instead of showing their other one.
    $this->company->users()->updateExistingPivot($this->owner->id, ['is_active' => false]);
    $this->get('/app')->assertRedirect(route('admin.tenants.show', $this->company->id));
    expect(Impersonation::active(session()->driver()))->toBeFalse();
});
