<?php

namespace Tests\Unit\Admin;

use App\Domain\Admin\Actions\CreateAdmin;
use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_active_admin_with_a_ulid_and_hashed_password(): void
    {
        $admin = (new CreateAdmin)->handle(' Jo Bloggs ', ' Jo@SSPOS.test ', 'secret-password-1', AdminRole::Support);

        $this->assertTrue(Str::isUlid($admin->id));
        $this->assertSame('Jo Bloggs', $admin->name);
        $this->assertSame('jo@sspos.test', $admin->email);
        $this->assertSame(AdminRole::Support, $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertNotSame('secret-password-1', $admin->password);
        $this->assertTrue(Hash::check('secret-password-1', $admin->password));
    }

    public function test_it_rejects_a_duplicate_email(): void
    {
        Admin::factory()->create(['email' => 'jo@sspos.test']);

        $this->expectException(ValidationException::class);

        (new CreateAdmin)->handle('Jo', 'JO@sspos.test', 'secret-password-1', AdminRole::Sales);
    }
}
