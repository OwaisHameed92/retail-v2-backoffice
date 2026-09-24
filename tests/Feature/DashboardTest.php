<?php

namespace Tests\Feature;

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/app')->assertRedirect('/login');
    }

    public function test_company_members_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        Company::factory()->withMember($user, CompanyRole::Staff)->create();

        $this->actingAs($user)->get('/app')->assertOk();
    }

    public function test_old_dashboard_url_is_gone(): void
    {
        $this->get('/dashboard')->assertNotFound();
    }
}
