<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Every protected admin route as [method, uri]. {id} is replaced with a real admin id.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function protectedRoutes(): array
    {
        return [
            ['get', '/admin'],
            ['post', '/admin/logout'],
            ['get', '/admin/admins'],
            ['get', '/admin/admins/create'],
            ['post', '/admin/admins'],
            ['get', '/admin/admins/{id}/edit'],
            ['put', '/admin/admins/{id}'],
            ['post', '/admin/admins/{id}/deactivate'],
            ['post', '/admin/admins/{id}/reactivate'],
        ];
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function managementRoutes(): array
    {
        return array_values(array_filter(
            $this->protectedRoutes(),
            fn (array $route) => str_starts_with($route[1], '/admin/admins'),
        ));
    }

    public function test_guests_are_redirected_to_admin_login_on_every_admin_route(): void
    {
        $target = Admin::factory()->create();

        foreach ($this->protectedRoutes() as [$method, $uri]) {
            $uri = str_replace('{id}', $target->id, $uri);

            $this->{$method}($uri)->assertRedirect('/admin/login');
        }
    }

    public function test_web_guests_still_go_to_the_tenant_login(): void
    {
        $this->get('/settings/profile')->assertRedirect('/login');
    }

    public function test_web_users_cannot_reach_any_admin_route(): void
    {
        $user = User::factory()->create();
        $target = Admin::factory()->owner()->create();

        foreach ($this->protectedRoutes() as [$method, $uri]) {
            $uri = str_replace('{id}', $target->id, $uri);

            $this->actingAs($user, 'web')->{$method}($uri)->assertRedirect('/admin/login');
        }

        $this->assertTrue($target->fresh()?->is_active);
        $this->assertGuest('admin');
    }

    #[DataProvider('nonOwnerRoles')]
    public function test_non_owner_admins_get_403_on_admin_user_management(AdminRole $role): void
    {
        $actor = Admin::factory()->role($role)->create();
        $target = Admin::factory()->create();

        foreach ($this->managementRoutes() as [$method, $uri]) {
            $uri = str_replace('{id}', $target->id, $uri);

            $this->actingAs($actor, 'admin')->{$method}($uri, [
                'name' => 'Changed',
                'email' => 'changed@sspos.test',
                'role' => 'owner',
                'password' => 'a-very-long-password',
                'password_confirmation' => 'a-very-long-password',
            ])->assertForbidden();
        }

        $this->assertDatabaseMissing('admins', ['email' => 'changed@sspos.test']);
        $this->assertNotSame('Changed', $target->fresh()?->name);
        $this->assertTrue($target->fresh()?->is_active);
    }

    /**
     * @return array<string, array{0: AdminRole}>
     */
    public static function nonOwnerRoles(): array
    {
        return [
            'sales' => [AdminRole::Sales],
            'support' => [AdminRole::Support],
            'accounts' => [AdminRole::Accounts],
        ];
    }

    public function test_any_active_admin_sees_the_dashboard_with_shared_admin_data(): void
    {
        $admin = Admin::factory()->role(AdminRole::Support)->create(['name' => 'Sam Support']);

        $this->actingAs($admin, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/dashboard')
                ->where('admin.name', 'Sam Support')
                ->where('admin.role', 'support')
                ->where('admin.roleLabel', 'Support')
                ->missing('admin.password'));
    }
}
