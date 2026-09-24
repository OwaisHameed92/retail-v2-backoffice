<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_first_owner(): void
    {
        $this->artisan('admin:create', ['email' => 'Owner@SSPOS.test'])
            ->expectsQuestion('Name', 'First Owner')
            ->expectsQuestion('Password (min 12 characters)', 'a-strong-password')
            ->expectsQuestion('Confirm password', 'a-strong-password')
            ->expectsOutputToContain('created with role Owner')
            ->assertSuccessful();

        $admin = Admin::query()->where('email', 'owner@sspos.test')->firstOrFail();
        $this->assertSame('First Owner', $admin->name);
        $this->assertSame(AdminRole::Owner, $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check('a-strong-password', $admin->password));
    }

    public function test_it_accepts_name_and_role_options(): void
    {
        $this->artisan('admin:create', ['email' => 'sales@sspos.test', '--name' => 'Sid', '--role' => 'sales'])
            ->expectsQuestion('Password (min 12 characters)', 'a-strong-password')
            ->expectsQuestion('Confirm password', 'a-strong-password')
            ->assertSuccessful();

        $this->assertSame(AdminRole::Sales, Admin::query()->firstOrFail()->role);
    }

    public function test_it_rejects_mismatched_or_short_passwords(): void
    {
        $this->artisan('admin:create', ['email' => 'owner@sspos.test', '--name' => 'Owner'])
            ->expectsQuestion('Password (min 12 characters)', 'short')
            ->expectsQuestion('Confirm password', 'different')
            ->assertFailed();

        $this->assertSame(0, Admin::query()->count());
    }

    public function test_it_rejects_an_unknown_role_and_duplicate_email(): void
    {
        Admin::factory()->create(['email' => 'taken@sspos.test']);

        $this->artisan('admin:create', ['email' => 'taken@sspos.test', '--name' => 'X', '--role' => 'god'])
            ->expectsQuestion('Password (min 12 characters)', 'a-strong-password')
            ->expectsQuestion('Confirm password', 'a-strong-password')
            ->expectsOutputToContain('The email has already been taken.')
            ->expectsOutputToContain('The selected role is invalid.')
            ->assertFailed();

        $this->assertSame(1, Admin::query()->count());
    }
}
