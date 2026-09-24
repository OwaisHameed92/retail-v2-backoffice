<?php

use App\Domain\Tenancy\Actions\CancelCompany;
use App\Domain\Tenancy\Actions\SuspendCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use App\Http\Middleware\EnsureCompanyMember;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
});

test('users of a suspended company see the on-hold page and no business data', function () {
    $company = $this->tenant('Khan Mini Mart');
    app(SuspendCompany::class)->handle($company, 'Unpaid');

    $this->actingAs($this->ownerOf($company))->get('/app')->assertOk()->assertInertia(fn ($page) => $page
        ->component('app/account-on-hold')
        ->where('companyName', 'Khan Mini Mart')
        ->where('otherCompanies', [])
        ->where('branches', [])
        ->where('currentBranchId', null));

    $this->assertAuthenticated();
});

test('the on-hold page replaces every tenant page, including settings', function () {
    $company = $this->tenant();
    app(SuspendCompany::class)->handle($company, 'Unpaid');

    $this->actingAs($this->ownerOf($company))->get('/settings/profile')
        ->assertInertia(fn ($page) => $page->component('app/account-on-hold'));
});

test('changes are refused while on hold', function () {
    $company = $this->tenant();
    app(SuspendCompany::class)->handle($company, 'Unpaid');
    $owner = $this->ownerOf($company);

    $this->actingAs($owner)->post('/app/branch/switch', ['branch_id' => $this->branchOf($company)->id])->assertRedirect(route('app.dashboard'));
    $this->actingAs($owner)->patch('/settings/profile', ['name' => 'Changed', 'email' => $owner->email])->assertRedirect(route('app.dashboard'));

    expect(session()->has('current_branch_id'))->toBeFalse()->and($owner->fresh()->name)->not->toBe('Changed');
});

test('a user of a suspended company can still open their other business', function () {
    $suspended = $this->tenant('Alpha Stores');
    $active = $this->tenant('Bravo Mart');
    $user = $this->addMember($suspended, CompanyRole::Manager);
    $active->users()->attach($user->id, ['role' => CompanyRole::Staff->value, 'is_active' => true]);
    app(SuspendCompany::class)->handle($suspended, 'Unpaid');

    $this->actingAs($user)->get('/app')->assertInertia(fn ($page) => $page
        ->component('app/account-on-hold')
        ->where('otherCompanies', [['id' => $active->id, 'name' => 'Bravo Mart']]));

    $this->actingAs($user)->post('/app/company/switch', ['company_id' => $active->id])->assertRedirect(route('app.dashboard'));
    $this->actingAs($user)->get('/app')->assertInertia(fn ($page) => $page->component('app/dashboard')->where('company.id', $active->id));
});

test('overdue companies keep using the portal', function () {
    $company = Company::factory()->status(CompanyStatus::Overdue)->create();
    $user = $this->addMember($company, CompanyRole::Owner);

    $this->actingAs($user)->get('/app')->assertOk()->assertInertia(fn ($page) => $page->component('app/dashboard'));
});

test('users of a cancelled company are signed out with a message', function () {
    $company = $this->tenant();
    $owner = $this->ownerOf($company);
    app(CancelCompany::class)->handle($company, 'Closed');

    $this->actingAs($owner)->get('/app')
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => EnsureCompanyMember::CANCELLED_MESSAGE]);

    $this->assertGuest();
});

test('a cancelled company is skipped when the user has another business', function () {
    $cancelled = $this->tenant('Alpha Stores');
    $active = $this->tenant('Bravo Mart');
    $user = $this->addMember($cancelled, CompanyRole::Owner);
    $active->users()->attach($user->id, ['role' => CompanyRole::Owner->value, 'is_active' => true]);
    app(CancelCompany::class)->handle($cancelled, 'Closed');

    $this->actingAs($user)->withSession(['current_company_id' => $cancelled->id])->get('/app')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('company.id', $active->id)->has('companies', 1));
});

test('signing in to a suspended account lands on the on-hold page', function () {
    $company = $this->tenant();
    $owner = $this->ownerOf($company);
    $owner->forceFill(['password' => 'correct-horse-battery'])->save();
    app(SuspendCompany::class)->handle($company, 'Unpaid');

    $this->post('/login', ['email' => $owner->email, 'password' => 'correct-horse-battery'])->assertRedirect('/app');
    $this->get('/app')->assertInertia(fn ($page) => $page->component('app/account-on-hold'));
});

test('signing in to a cancelled account ends with the closed message', function () {
    $company = $this->tenant();
    $owner = $this->ownerOf($company);
    $owner->forceFill(['password' => 'correct-horse-battery'])->save();
    app(CancelCompany::class)->handle($company, 'Closed');

    $this->post('/login', ['email' => $owner->email, 'password' => 'correct-horse-battery'])->assertRedirect('/app');
    $this->get('/app')->assertRedirect(route('login'))->assertSessionHasErrors(['email' => EnsureCompanyMember::CANCELLED_MESSAGE]);
    $this->assertGuest();
});

test('lifting a suspension gives the portal back', function () {
    $company = $this->tenant();
    $owner = $this->ownerOf($company);
    app(SuspendCompany::class)->handle($company, 'Unpaid');
    $company->forceFill(['status' => CompanyStatus::Active])->save();

    $this->actingAs($owner)->get('/app')->assertInertia(fn ($page) => $page->component('app/dashboard'));
});

test('users without any membership still get the not-linked message', function () {
    $this->actingAs(User::factory()->create())->get('/app')
        ->assertSessionHasErrors(['email' => EnsureCompanyMember::NOT_LINKED_MESSAGE]);
});
