<?php

namespace Tests\Unit\Admin;

use App\Domain\Admin\Actions\UpdateAdmin;
use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UpdateAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_details_and_optionally_the_password(): void
    {
        $admin = Admin::factory()->create();
        $token = $admin->remember_token;

        (new UpdateAdmin)->handle($admin, 'New', 'NEW@sspos.test', AdminRole::Accounts, 'another-password-1');

        $admin->refresh();
        $this->assertSame('New', $admin->name);
        $this->assertSame('new@sspos.test', $admin->email);
        $this->assertSame(AdminRole::Accounts, $admin->role);
        $this->assertTrue(Hash::check('another-password-1', $admin->password));
        $this->assertNotSame($token, $admin->remember_token);
    }

    public function test_null_password_keeps_the_current_one(): void
    {
        $admin = Admin::factory()->create();

        (new UpdateAdmin)->handle($admin, 'Same', $admin->email, AdminRole::Support, null);

        $this->assertTrue(Hash::check('password', (string) $admin->fresh()?->password));
    }

    public function test_it_rejects_an_email_used_by_another_admin(): void
    {
        Admin::factory()->create(['email' => 'taken@sspos.test']);
        $admin = Admin::factory()->create();

        $this->expectException(ValidationException::class);

        (new UpdateAdmin)->handle($admin, 'X', 'taken@sspos.test', AdminRole::Support);
    }

    public function test_the_last_active_owner_cannot_lose_the_owner_role(): void
    {
        $owner = Admin::factory()->owner()->create();
        Admin::factory()->owner()->inactive()->create();

        try {
            (new UpdateAdmin)->handle($owner, $owner->name, $owner->email, AdminRole::Sales);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('role', $e->errors());
        }

        $this->assertSame(AdminRole::Owner, $owner->fresh()?->role);
    }

    public function test_an_owner_can_be_demoted_when_another_active_owner_exists(): void
    {
        $owner = Admin::factory()->owner()->create();
        Admin::factory()->owner()->create();

        (new UpdateAdmin)->handle($owner, $owner->name, $owner->email, AdminRole::Sales);

        $this->assertSame(AdminRole::Sales, $owner->fresh()?->role);
    }
}
