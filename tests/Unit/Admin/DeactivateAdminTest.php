<?php

namespace Tests\Unit\Admin;

use App\Domain\Admin\Actions\DeactivateAdmin;
use App\Domain\Admin\Actions\ReactivateAdmin;
use App\Domain\Admin\Actions\RecordAdminLogin;
use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeactivateAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deactivates_an_admin_and_rotates_the_remember_token(): void
    {
        $actor = Admin::factory()->owner()->create();
        $target = Admin::factory()->role(AdminRole::Sales)->create();
        $token = $target->remember_token;

        (new DeactivateAdmin)->handle($target, $actor);

        $target->refresh();
        $this->assertFalse($target->is_active);
        $this->assertNotSame($token, $target->remember_token);
    }

    public function test_you_cannot_deactivate_yourself(): void
    {
        $actor = Admin::factory()->owner()->create();
        Admin::factory()->owner()->create();

        $this->expectException(ValidationException::class);

        (new DeactivateAdmin)->handle($actor, $actor);
    }

    public function test_the_last_active_owner_cannot_be_deactivated(): void
    {
        $lastOwner = Admin::factory()->owner()->create();
        Admin::factory()->owner()->inactive()->create();
        $actor = Admin::factory()->role(AdminRole::Support)->create();

        try {
            (new DeactivateAdmin)->handle($lastOwner, $actor);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame('You cannot deactivate the last active owner.', $e->errors()['admin'][0]);
        }

        $this->assertTrue($lastOwner->fresh()?->is_active);
    }

    public function test_an_owner_can_be_deactivated_when_another_active_owner_exists(): void
    {
        $actor = Admin::factory()->owner()->create();
        $other = Admin::factory()->owner()->create();

        (new DeactivateAdmin)->handle($other, $actor);

        $this->assertFalse($other->fresh()?->is_active);
    }

    public function test_reactivate_and_record_login(): void
    {
        $admin = Admin::factory()->inactive()->create();

        (new ReactivateAdmin)->handle($admin);
        $this->assertTrue($admin->fresh()?->is_active);

        $this->freezeSecond();
        (new RecordAdminLogin)->handle($admin);
        $this->assertTrue(now()->equalTo($admin->fresh()?->last_login_at));
    }
}
