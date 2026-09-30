<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Http\Middleware\EnsureCompanyMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class CompanyMiddlewareTest extends TestCase
{
    use RefreshDatabase, TenancyTestHelpers;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/app')->assertRedirect('/login');
        $this->post('/app/company/switch', ['company_id' => Company::factory()->create()->id])->assertRedirect('/login');
        $this->post('/app/branch/switch', ['branch_id' => ''])->assertRedirect('/login');
    }

    public function test_user_without_membership_is_logged_out_with_a_message(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/app')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => EnsureCompanyMember::NOT_LINKED_MESSAGE]);

        $this->assertGuest();
    }

    public function test_inactive_membership_is_denied(): void
    {
        $user = $this->memberOf(Company::factory()->create(), active: false);

        $this->actingAs($user)->get('/app')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_membership_of_a_soft_deleted_company_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = $this->memberOf($company);
        $company->delete();

        $this->actingAs($user)->get('/app')->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_member_sees_the_dashboard_with_tenant_props(): void
    {
        $company = Company::factory()->create(['name' => 'Alpha Stores']);
        $user = $this->memberOf($company, CompanyRole::Accountant);

        $this->actingAs($user)->get('/app')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('app/dashboard')
                ->where('company.id', $company->id)
                ->where('company.name', 'Alpha Stores')
                ->where('company.status', 'active')
                ->where('companyRole', 'accountant')
                ->where('abilities', ['dashboard.view', 'sales.view', 'stock.view', 'reports.view', 'billing.view', 'shops.view', 'cash.view', 'purchasing.view', 'staff.view', 'transfers.view', 'accounts.view'])
                ->has('companies', 1));
    }

    public function test_user_can_switch_to_another_company_they_belong_to(): void
    {
        $alpha = Company::factory()->create(['name' => 'Alpha Stores']);
        $bravo = Company::factory()->create(['name' => 'Bravo Mart']);
        $user = $this->memberOf($alpha);
        $this->memberOf($bravo, CompanyRole::Staff, user: $user);

        $this->actingAs($user)->get('/app')->assertInertia(fn ($page) => $page
            ->where('company.id', $alpha->id)
            ->has('companies', 2));

        $this->actingAs($user)->post('/app/company/switch', ['company_id' => $bravo->id])
            ->assertRedirect(route('app.dashboard'))
            ->assertSessionHas('current_company_id', $bravo->id);

        $this->actingAs($user)->get('/app')->assertInertia(fn ($page) => $page
            ->where('company.id', $bravo->id)
            ->where('companyRole', 'staff'));
    }

    public function test_user_cannot_switch_to_a_company_they_do_not_belong_to(): void
    {
        $alpha = Company::factory()->create();
        $other = Company::factory()->create();
        $user = $this->memberOf($alpha);

        $this->actingAs($user)->post('/app/company/switch', ['company_id' => $other->id])->assertForbidden();

        $this->assertNotSame($other->id, session('current_company_id'));
    }

    public function test_user_cannot_switch_to_a_company_where_membership_is_inactive(): void
    {
        $alpha = Company::factory()->create();
        $bravo = Company::factory()->create();
        $user = $this->memberOf($alpha);
        $this->memberOf($bravo, active: false, user: $user);

        $this->actingAs($user)->post('/app/company/switch', ['company_id' => $bravo->id])->assertForbidden();
    }

    public function test_stale_session_company_falls_back_to_an_allowed_company(): void
    {
        $alpha = Company::factory()->create();
        $other = Company::factory()->create();
        $user = $this->memberOf($alpha);

        $this->actingAs($user)->withSession(['current_company_id' => $other->id])->get('/app')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('company.id', $alpha->id));
    }

    public function test_company_can_denies_a_missing_ability_with_403(): void
    {
        Route::middleware(['web', 'auth', 'company', 'company.can:users.manage'])
            ->get('/_test/users', fn () => 'ok');

        $company = Company::factory()->create();

        $manager = $this->memberOf($company, CompanyRole::Manager);
        $this->actingAs($manager)->get('/_test/users')->assertForbidden();

        $owner = $this->memberOf($company, CompanyRole::Owner);
        $this->actingAs($owner)->get('/_test/users')->assertOk();
    }

    public function test_company_can_denies_unknown_abilities(): void
    {
        Route::middleware(['web', 'auth', 'company', 'company.can:does.not.exist'])
            ->get('/_test/unknown', fn () => 'ok');

        $owner = $this->memberOf(Company::factory()->create(), CompanyRole::Owner);

        $this->actingAs($owner)->get('/_test/unknown')->assertForbidden();
    }
}
