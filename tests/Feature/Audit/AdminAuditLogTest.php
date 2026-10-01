<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Audit\Queries\AuditSearch;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * /admin/audit-log: every entry across every business, owner and support only.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->support = Admin::factory()->create(['role' => AdminRole::Support, 'name' => 'Sam Support']);
    $this->khan = Company::factory()->create(['name' => 'Khan Mini Mart']);
    $this->patel = Company::factory()->create(['name' => 'Patel News']);
    $this->user = User::factory()->create(['name' => 'Aisha Khan', 'email' => 'aisha@khan.test']);
});

function auditEntry(array $attributes): AuditLog
{
    return AuditLog::query()->create(array_merge([
        'action' => 'licence.suspended',
        'subject_type' => 'App\\Domain\\Licensing\\Models\\Licence',
        'subject_id' => '01K5VB0000000000000000LIC1',
        'before' => ['status' => 'active'],
        'after' => ['status' => 'suspended'],
        'ip' => '203.0.113.5',
    ], $attributes));
}

test('owner and support admins can open the audit log; sales and accounts cannot', function (AdminRole $role, bool $allowed) {
    $admin = Admin::factory()->create(['role' => $role]);

    $response = $this->actingAs($admin, 'admin')->get('/admin/audit-log');
    $allowed ? $response->assertOk() : $response->assertForbidden();

    $export = $this->get('/admin/audit-log/export');
    $allowed ? $export->assertOk() : $export->assertForbidden();
})->with([
    'owner' => [AdminRole::Owner, true],
    'support' => [AdminRole::Support, true],
    'sales' => [AdminRole::Sales, false],
    'accounts' => [AdminRole::Accounts, false],
]);

test('guests and portal users are sent to the admin sign-in', function () {
    $this->get('/admin/audit-log')->assertRedirect(route('admin.login'));
    $this->actingAs($this->user)->get('/admin/audit-log/export')->assertRedirect(route('admin.login'));
});

test('entries show who, which business, what and a field-by-field diff', function () {
    auditEntry([
        'company_id' => $this->khan->id,
        'actor_type' => $this->support->getMorphClass(),
        'actor_id' => $this->support->id,
        'before' => ['status' => 'active', 'note' => null, 'same' => 1],
        'after' => ['status' => 'suspended', 'note' => 'Unpaid', 'same' => 1],
        'meta' => ['reason' => 'Unpaid invoice'],
    ]);

    $this->actingAs($this->support, 'admin')->get('/admin/audit-log')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/audit-log/index')
        ->has('entries.data', 1)
        ->where('entries.data.0.actionLabel', 'Licence suspended')
        ->where('entries.data.0.actor.name', 'Sam Support')
        ->where('entries.data.0.actor.type', 'admin')
        ->where('entries.data.0.company.name', 'Khan Mini Mart')
        ->where('entries.data.0.subject.label', 'Licence')
        ->where('entries.data.0.ip', '203.0.113.5')
        ->where('entries.data.0.changes', [
            ['field' => 'status', 'label' => 'Status', 'before' => 'active', 'after' => 'suspended'],
            ['field' => 'note', 'label' => 'Note', 'before' => null, 'after' => 'Unpaid'],
        ])
        ->where('entries.data.0.meta', [['key' => 'reason', 'label' => 'Reason', 'value' => 'Unpaid invoice']])
        ->where('options.actions', fn ($actions) => collect($actions)->pluck('value')->all() === ['licence.*', 'licence.suspended'])
        ->where('options.actors', fn ($actors) => collect($actors)->contains('value', 'admin:'.$this->support->id)));
});

test('filters narrow by business, person, action, record, days and search', function () {
    $userType = $this->user->getMorphClass();
    $a = auditEntry(['company_id' => $this->khan->id, 'actor_type' => $userType, 'actor_id' => (string) $this->user->id, 'action' => 'product.updated', 'subject_id' => 'P1', 'subject_type' => 'App\\Product']);
    $b = auditEntry(['company_id' => $this->patel->id, 'action' => 'licence.suspended']);
    $c = auditEntry(['company_id' => $this->patel->id, 'action' => 'licence.renewed', 'actor_type' => $this->support->getMorphClass(), 'actor_id' => $this->support->id, 'ip' => '198.51.100.9']);
    AuditLog::query()->whereKey($a->id)->toBase()->update(['created_at' => Carbon::parse('2026-09-01 12:00:00', 'UTC')]);

    $ids = fn (string $query) => collect($this->actingAs($this->support, 'admin')->get('/admin/audit-log'.$query)->assertOk()
        ->inertiaProps('entries.data'))->pluck('id')->sort()->values()->all();

    expect($ids('?company='.$this->patel->id))->toBe(collect([$b->id, $c->id])->sort()->values()->all())
        ->and($ids('?actor=user:'.$this->user->id))->toBe([$a->id])
        ->and($ids('?actor=system'))->toBe([$b->id])
        ->and($ids('?action=licence.*'))->toBe(collect([$b->id, $c->id])->sort()->values()->all())
        ->and($ids('?action=licence.renewed'))->toBe([$c->id])
        ->and($ids('?subjectType=App%5CProduct&subjectId=P1'))->toBe([$a->id])
        ->and($ids('?from=2026-09-01&to=2026-09-01'))->toBe([$a->id])
        ->and($ids('?to=2026-09-02'))->toBe([$a->id])
        ->and($ids('?search=198.51.100.9'))->toBe([$c->id])
        ->and($ids('?search=renew'))->toBe([$c->id])
        ->and($ids('?actor=nonsense&company=bad'))->toHaveCount(3);
});

test('the list pages newest first without counting: older and newer cursors', function () {
    $entries = collect(range(1, 30))->map(fn (int $n) => auditEntry(['company_id' => $this->khan->id]));
    // Times out of id order (a backdated entry) must still list by time.
    AuditLog::query()->whereKey($entries[29]->id)->toBase()->update(['created_at' => now()->subYear()]);
    $newestFirst = AuditLog::query()->orderByDesc('created_at')->orderByDesc('id')->get();
    $ids = $newestFirst->pluck('id');
    $cursor = fn (int $i) => AuditSearch::encode($newestFirst[$i]);

    $first = $this->actingAs($this->support, 'admin')->get('/admin/audit-log?perPage=25')->assertOk();
    expect(collect($first->inertiaProps('entries.data'))->pluck('id')->all())->toBe($ids->take(25)->all())
        ->and($first->inertiaProps('entries.newer'))->toBeNull()
        ->and($first->inertiaProps('entries.older'))->toBe($cursor(24));

    $second = $this->get('/admin/audit-log?perPage=25&after='.$cursor(24));
    expect(collect($second->inertiaProps('entries.data'))->pluck('id')->all())->toBe($ids->slice(25)->values()->all())
        ->and($second->inertiaProps('entries.data.4.id'))->toBe($entries[29]->id)
        ->and($second->inertiaProps('entries.older'))->toBeNull()
        ->and($second->inertiaProps('entries.newer'))->toBe($cursor(25));

    $back = $this->get('/admin/audit-log?perPage=25&before='.$cursor(25));
    expect(collect($back->inertiaProps('entries.data'))->pluck('id')->all())->toBe($ids->take(25)->all());

    $this->get('/admin/audit-log?after=not-a-cursor')->assertOk()->assertInertia(fn (Assert $page) => $page->has('entries.data', 30));
});

test('the CSV has every matching entry with UK times, and the export is audited', function () {
    $sneaky = User::factory()->create(['name' => '=HYPERLINK("x")']);
    auditEntry(['company_id' => $this->khan->id, 'action' => 'licence.suspended', 'actor_type' => $sneaky->getMorphClass(), 'actor_id' => (string) $sneaky->id]);
    auditEntry(['company_id' => $this->patel->id, 'action' => 'licence.renewed']);
    AuditLog::query()->toBase()->update(['created_at' => Carbon::parse('2026-07-01 08:30:00', 'UTC')]);

    $response = $this->actingAs($this->support, 'admin')->get('/admin/audit-log/export?company='.$this->khan->id);
    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    $csv = $response->streamedContent();
    $lines = array_values(array_filter(explode("\n", trim($csv))));

    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toStartWith('"Time (UK)",Who,"Who (detail)",Business,Action')
        ->and($lines[1])->toContain('2026-07-01 09:30:00')
        ->and($lines[1])->toContain('Khan Mini Mart')
        ->and($lines[1])->toContain('Status: active → suspended')
        ->and($csv)->not->toContain('Patel News')
        ->and($csv)->toContain('"\'=HYPERLINK(""x"")"');

    $entry = AuditLog::query()->where('action', 'audit_log.exported')->sole();
    expect($entry->actor_id)->toBe($this->support->id)->and($entry->meta)->toBe(['company' => $this->khan->id]);
});

test('the audit log is in the admin navigation for owner and support only', function () {
    $this->actingAs($this->support, 'admin')->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('admin.abilities', fn ($abilities) => collect($abilities)->contains('audit.view')));

    $sales = Admin::factory()->create(['role' => AdminRole::Sales]);
    $this->actingAs($sales, 'admin')->get('/admin')->assertInertia(fn (Assert $page) => $page
        ->where('admin.abilities', fn ($abilities) => ! collect($abilities)->contains('audit.view')));
});
