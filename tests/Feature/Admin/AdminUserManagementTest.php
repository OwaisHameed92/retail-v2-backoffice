<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private Admin $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->owner = Admin::factory()->owner()->create(['name' => 'Olivia Owner']);
    }

    public function test_owner_sees_the_admin_list_without_secrets(): void
    {
        Admin::factory()->role(AdminRole::Sales)->create(['name' => 'Sid Sales']);

        $this->actingAs($this->owner, 'admin')
            ->get('/admin/admins')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/admins/index')
                ->has('admins', 2)
                ->where('admins.0.name', 'Olivia Owner')
                ->where('admins.1.roleLabel', 'Sales')
                ->missing('admins.0.password')
                ->missing('admins.0.remember_token'));
    }

    public function test_owner_sees_the_create_and_edit_forms(): void
    {
        $target = Admin::factory()->create();

        $this->actingAs($this->owner, 'admin')->get('/admin/admins/create')
            ->assertInertia(fn (Assert $page) => $page->component('admin/admins/create')->has('roles', 4));

        $this->actingAs($this->owner, 'admin')->get("/admin/admins/{$target->id}/edit")
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/admins/edit')
                ->where('editAdmin.id', $target->id)
                ->where('isSelf', false));
    }

    public function test_owner_can_create_an_admin(): void
    {
        $this->actingAs($this->owner, 'admin')
            ->post('/admin/admins', [
                'name' => 'Ava Accounts',
                'email' => 'ava@sspos.test',
                'role' => 'accounts',
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
            ])
            ->assertRedirect('/admin/admins')
            ->assertSessionHas('status');

        $admin = Admin::query()->where('email', 'ava@sspos.test')->firstOrFail();
        $this->assertSame(AdminRole::Accounts, $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check('correct-horse-battery', $admin->password));
    }

    public function test_create_validates_input(): void
    {
        $this->actingAs($this->owner, 'admin')
            ->post('/admin/admins', [
                'name' => '',
                'email' => $this->owner->email,
                'role' => 'superuser',
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertSessionHasErrors(['name', 'email', 'role', 'password']);

        $this->assertSame(1, Admin::query()->count());
    }

    public function test_owner_can_update_an_admin_and_keep_the_password(): void
    {
        $target = Admin::factory()->role(AdminRole::Support)->create();
        $oldHash = $target->password;

        $this->actingAs($this->owner, 'admin')
            ->put("/admin/admins/{$target->id}", [
                'name' => 'New Name',
                'email' => 'new@sspos.test',
                'role' => 'sales',
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect('/admin/admins');

        $target->refresh();
        $this->assertSame('New Name', $target->name);
        $this->assertSame('new@sspos.test', $target->email);
        $this->assertSame(AdminRole::Sales, $target->role);
        $this->assertSame($oldHash, $target->password);
    }

    public function test_the_only_owner_cannot_demote_themselves(): void
    {
        $this->actingAs($this->owner, 'admin')
            ->put("/admin/admins/{$this->owner->id}", [
                'name' => $this->owner->name,
                'email' => $this->owner->email,
                'role' => 'support',
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame(AdminRole::Owner, $this->owner->fresh()?->role);
    }

    public function test_owner_can_deactivate_and_reactivate_an_admin(): void
    {
        $target = Admin::factory()->create();

        $this->actingAs($this->owner, 'admin')
            ->post("/admin/admins/{$target->id}/deactivate")
            ->assertRedirect('/admin/admins');
        $this->assertFalse($target->fresh()?->is_active);

        $this->actingAs($this->owner, 'admin')
            ->post("/admin/admins/{$target->id}/reactivate")
            ->assertRedirect('/admin/admins');
        $this->assertTrue($target->fresh()?->is_active);
    }

    public function test_owner_cannot_deactivate_themselves(): void
    {
        $this->actingAs($this->owner, 'admin')
            ->from('/admin/admins')
            ->post("/admin/admins/{$this->owner->id}/deactivate")
            ->assertRedirect('/admin/admins')
            ->assertSessionHasErrors('admin');

        $this->assertTrue($this->owner->fresh()?->is_active);
    }

    public function test_owner_can_deactivate_another_owner_when_one_remains(): void
    {
        $otherOwner = Admin::factory()->owner()->create();

        $this->actingAs($this->owner, 'admin')
            ->post("/admin/admins/{$otherOwner->id}/deactivate")
            ->assertSessionHasNoErrors();

        $this->assertFalse($otherOwner->fresh()?->is_active);
    }

    public function test_unknown_admin_returns_404(): void
    {
        $this->actingAs($this->owner, 'admin')
            ->get('/admin/admins/01J00000000000000000000000/edit')
            ->assertNotFound();
    }
}
