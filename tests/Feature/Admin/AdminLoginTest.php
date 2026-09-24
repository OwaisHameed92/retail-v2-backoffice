<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_login_page_renders(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/auth/login'));
    }

    public function test_admin_can_log_in_and_last_login_is_recorded(): void
    {
        $admin = Admin::factory()->create(['email' => 'staff@sspos.test']);

        $this->post('/admin/login', ['email' => 'staff@sspos.test', 'password' => 'password'])
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertGuest('web');
        $this->assertNotNull($admin->fresh()?->last_login_at);
    }

    public function test_email_is_case_insensitive(): void
    {
        $admin = Admin::factory()->create(['email' => 'staff@sspos.test']);

        $this->post('/admin/login', ['email' => ' Staff@SSPOS.test', 'password' => 'password']);

        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $admin = Admin::factory()->create();

        $this->from('/admin/login')
            ->post('/admin/login', ['email' => $admin->email, 'password' => 'wrong-password'])
            ->assertRedirect('/admin/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest('admin');
        $this->assertNull($admin->fresh()?->last_login_at);
    }

    public function test_inactive_admin_cannot_log_in(): void
    {
        $admin = Admin::factory()->inactive()->create();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    }

    public function test_web_user_credentials_do_not_work_on_admin_login(): void
    {
        $user = User::factory()->create();

        $this->post('/admin/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest('admin');
        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        $admin = Admin::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', ['email' => $admin->email, 'password' => 'wrong-password']);
        }

        // Even the correct password is refused while locked out.
        $response = $this->post('/admin/login', ['email' => $admin->email, 'password' => 'password']);

        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many login attempts', (string) session('errors')->first('email'));
        $this->assertGuest('admin');

        RateLimiter::clear('admin-login|'.strtolower($admin->email).'|127.0.0.1');
    }

    public function test_signed_in_admin_visiting_login_goes_to_dashboard(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin')
            ->get('/admin/login')
            ->assertRedirect('/admin');
    }

    public function test_admin_can_log_out(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin')
            ->post('/admin/logout')
            ->assertRedirect('/admin/login');

        $this->assertGuest('admin');
    }

    public function test_admin_deactivated_while_signed_in_is_signed_out(): void
    {
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        $admin->forceFill(['is_active' => false])->save();

        $this->get('/admin')
            ->assertRedirect('/admin/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    }
}
