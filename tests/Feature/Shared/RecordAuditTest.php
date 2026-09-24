<?php

namespace Tests\Feature\Shared;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Shared\Support\Redactor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class RecordAuditTest extends TestCase
{
    use RefreshDatabase;

    private function record(): RecordAudit
    {
        return app(RecordAudit::class);
    }

    public function test_it_records_the_web_user_as_actor(): void
    {
        $user = User::factory()->create();
        $subject = User::factory()->create();
        $this->actingAs($user, 'web');

        $log = $this->record()->handle('user.renamed', $subject, ['name' => 'Old'], ['name' => 'New']);

        $this->assertSame($user->getMorphClass(), $log->actor_type);
        $this->assertSame((string) $user->id, $log->actor_id);
        $this->assertSame($subject->getMorphClass(), $log->subject_type);
        $this->assertSame((string) $subject->id, $log->subject_id);
        $this->assertSame(['name' => 'Old'], $log->fresh()?->before);
        $this->assertSame(['name' => 'New'], $log->fresh()?->after);
        $this->assertSame(26, strlen($log->id));
        $this->assertNotNull($log->created_at);
    }

    public function test_it_prefers_the_admin_guard(): void
    {
        config(['auth.guards.admin' => ['driver' => 'session', 'provider' => 'users']]);
        $admin = User::factory()->create();
        $customer = User::factory()->create();
        $this->actingAs($customer, 'web');
        $this->actingAs($admin, 'admin');

        $log = $this->record()->handle('licence.suspended', null, null, null);

        $this->assertSame((string) $admin->id, $log->actor_id);
    }

    public function test_an_explicit_actor_and_company_win(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $job = User::factory()->create();

        $log = $this->record()->handle('sync.pushed', null, null, null, ['rows' => 10], actor: $job, companyId: '01K5VB000000000000000000AA');

        $this->assertSame((string) $job->id, $log->actor_id);
        $this->assertSame('01K5VB000000000000000000AA', $log->company_id);
        $this->assertSame(['rows' => 10], $log->meta);
    }

    public function test_no_actor_when_nobody_is_signed_in(): void
    {
        $log = $this->record()->handle('system.cleanup', null, null, null);

        $this->assertNull($log->actor_type);
        $this->assertNull($log->actor_id);
        $this->assertNull($log->meta);
    }

    public function test_secrets_are_redacted(): void
    {
        $log = $this->record()->handle(
            'licence.issued',
            null,
            ['password' => 'hunter2', 'status' => 'issued'],
            ['licenceKey' => 'SSP-AAAA-BBBB-CCCC-DDDD', 'key_hash' => 'abc', 'keyLast4' => 'DDDD', 'nested' => ['api_key' => 'k', 'remember_token' => 't']],
            ['token' => 'jws', 'Authorization' => 'Bearer x', 'reason' => 'new till'],
        );

        $stored = AuditLog::query()->findOrFail($log->id);
        $json = json_encode([$stored->before, $stored->after, $stored->meta]);

        $this->assertIsString($json);
        foreach (['hunter2', 'SSP-AAAA', '"abc"', 'jws', 'Bearer'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertSame(Redactor::REDACTED, $stored->after['licenceKey'] ?? null);
        $this->assertSame(Redactor::REDACTED, $stored->after['nested']['api_key'] ?? null);
        $this->assertSame('DDDD', $stored->after['keyLast4'] ?? null);
        $this->assertSame('issued', $stored->before['status'] ?? null);
        $this->assertSame('new till', $stored->meta['reason'] ?? null);
    }

    public function test_entries_cannot_be_updated(): void
    {
        $log = $this->record()->handle('a.b', null, null, null);

        $this->expectException(LogicException::class);
        $log->update(['action' => 'changed']);
    }

    public function test_entries_cannot_be_deleted(): void
    {
        $log = $this->record()->handle('a.b', null, null, null);

        try {
            $log->delete();
            $this->fail('Delete should throw.');
        } catch (LogicException) {
            $this->assertDatabaseHas('audit_logs', ['id' => $log->id]);
        }
    }
}
