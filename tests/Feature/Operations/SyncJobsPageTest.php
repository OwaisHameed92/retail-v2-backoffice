<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Operations\Models\ScheduledTaskRun;
use App\Domain\Shared\Support\SchedulerHeartbeat;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Enums\CompanyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class);

/*
 * Admin "Sync & jobs": health strip, scheduled tasks, failed jobs, sync problems and till traffic. audit.view only.
 */

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:00', 'UTC'));
});

test('owner and support can open the page; sales and accounts get 403; guests and portal users go to the admin sign-in', function () {
    $this->get('/admin/sync-jobs')->assertRedirect(route('admin.login'));

    $company = $this->tenant('Khan Mini Mart');
    $this->actingAs($this->ownerOf($company))->get('/admin/sync-jobs')->assertRedirect(route('admin.login'));

    foreach ([AdminRole::Owner, AdminRole::Support] as $role) {
        $this->actingAs($this->admin($role), 'admin')->get('/admin/sync-jobs')->assertOk();
    }

    foreach ([AdminRole::Sales, AdminRole::Accounts] as $role) {
        $admin = $this->admin($role);
        $this->actingAs($admin, 'admin')->get('/admin/sync-jobs')->assertForbidden();
        $this->actingAs($admin, 'admin')->get(route('admin.sync-jobs.failed-jobs.show', '0b0b0b0b-0000-4000-8000-000000000001'))->assertForbidden();
        $this->actingAs($admin, 'admin')->post(route('admin.sync-jobs.failed-jobs.retry'), ['uuids' => ['0b0b0b0b-0000-4000-8000-000000000001']])->assertForbidden();
        $this->actingAs($admin, 'admin')->delete(route('admin.sync-jobs.failed-jobs.destroy'), ['uuids' => ['0b0b0b0b-0000-4000-8000-000000000001']])->assertForbidden();
    }

    foreach (['admin.sync-jobs.index', 'admin.sync-jobs.failed-jobs.show', 'admin.sync-jobs.failed-jobs.retry', 'admin.sync-jobs.failed-jobs.destroy'] as $name) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('auth:admin', 'can:'.AdminRole::AUDIT_VIEW);
    }

    expect(preg_match("/title: 'Sync & jobs',[^}]*route: 'admin\\.sync-jobs\\.index',[^}]*ability: 'audit\\.view'/s", (string) file_get_contents(resource_path('js/components/admin/admin-nav.ts'))))->toBe(1);
});

test('renders with empty tables: scheduler not running, nothing failed, no sync problems, no traffic', function () {
    $this->actingAs($this->admin(AdminRole::Support), 'admin')->get('/admin/sync-jobs')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/sync-jobs/index')
        ->where('checkedAt', '2026-11-08T12:00:00Z')
        ->has('health', 4)
        ->where('health.0.key', 'scheduler')
        ->where('health.0.state', 'problem')
        ->where('health.1.key', 'queue')
        ->where('health.1.state', 'ok')
        ->where('health.2.key', 'failed')
        ->where('health.2.state', 'ok')
        ->where('health.2.value', '0')
        ->where('health.3.key', 'sync')
        ->where('health.3.state', 'ok')
        ->has('tasks', fn (Assert $tasks) => $tasks->each(fn (Assert $task) => $task->where('lastRun', null)->where('runs7d', 0)->etc()))
        ->where('failedJobs.data', [])
        ->where('failedJobs.meta.total', 0)
        ->where('syncProblems.rows', [])
        ->where('traffic.recent', [])
        ->where('traffic.quiet', [])
    );
});

test('the scheduler is green with a fresh heartbeat and red after 3 minutes', function () {
    SchedulerHeartbeat::beat(CarbonImmutable::now()->subMinutes(2));
    $admin = $this->admin();

    $this->actingAs($admin, 'admin')->get('/admin/sync-jobs')->assertInertia(fn (Assert $page) => $page
        ->where('health.0.state', 'ok')->where('health.0.value', 'Running')->where('health.0.at', '2026-11-08T11:58:00Z'));

    SchedulerHeartbeat::beat(CarbonImmutable::now()->subMinutes(4));

    $this->actingAs($admin, 'admin')->get('/admin/sync-jobs')->assertInertia(fn (Assert $page) => $page
        ->where('health.0.state', 'problem')->where('health.0.value', 'Stopped'));
});

test('the queue backlog counts waiting jobs and turns amber, then red, with the age of the oldest', function () {
    config(['queue.default' => 'database']);
    $job = fn (int $minutesAgo, ?int $reserved = null) => DB::table('jobs')->insert([
        'queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => $reserved,
        'available_at' => now()->subMinutes($minutesAgo)->getTimestamp(), 'created_at' => now()->subMinutes($minutesAgo)->getTimestamp(),
    ]);
    $admin = $this->admin();

    $job(1);
    $job(2, now()->getTimestamp());
    $this->actingAs($admin, 'admin')->get('/admin/sync-jobs')->assertInertia(fn (Assert $page) => $page
        ->where('health.1.state', 'ok')->where('health.1.value', '1 waiting')->where('health.1.detail', '1 running now.'));

    $job(10);
    $this->actingAs($admin, 'admin')->get('/admin/sync-jobs')->assertInertia(fn (Assert $page) => $page
        ->where('health.1.state', 'warning')->where('health.1.value', '2 waiting')->where('health.1.at', '2026-11-08T11:50:00Z'));

    $job(45);
    $this->actingAs($admin, 'admin')->get('/admin/sync-jobs')->assertInertia(fn (Assert $page) => $page->where('health.1.state', 'problem'));
});

test('scheduled tasks list every task in routes/console.php with its last run, 7-day counts and next due time', function () {
    ScheduledTaskRun::query()->create(['task' => 'billing:run', 'expression' => '0 6 * * *', 'started_at' => now()->subDays(2), 'finished_at' => now()->subDays(2)->addSeconds(3), 'duration_ms' => 3000, 'succeeded' => true, 'exit_code' => 0]);
    ScheduledTaskRun::query()->create(['task' => 'billing:run', 'expression' => '0 6 * * *', 'started_at' => now()->subHours(6), 'finished_at' => now()->subHours(6)->addSeconds(1), 'duration_ms' => 1200, 'succeeded' => false, 'exit_code' => 1, 'error' => 'RuntimeException: token=abc123secretvalue']);
    ScheduledTaskRun::query()->create(['task' => 'licence:keys:prune', 'expression' => '0 0 * * *', 'started_at' => now()->subDays(3), 'finished_at' => now()->subDays(3), 'duration_ms' => 40, 'succeeded' => true, 'exit_code' => 0]);

    $this->actingAs($this->admin(), 'admin')->get('/admin/sync-jobs')->assertOk()->assertInertia(function (Assert $page) {
        $tasks = collect($page->toArray()['props']['tasks'])->keyBy('task');

        expect($tasks->has('scheduler:heartbeat'))->toBeTrue()
            ->and($tasks->has('model:prune --model=ScheduledTaskRun'))->toBeTrue()
            ->and($tasks['billing:run'])->toMatchArray([
                'frequency' => 'Daily at 06:00',
                'expression' => '0 6 * * *',
                'timezone' => 'UTC',
                'nextDueAt' => '2026-11-09T06:00:00Z',
                'late' => false,
                'runs7d' => 2,
                'failed7d' => 1,
            ])
            ->and($tasks['billing:run']['lastRun'])->toMatchArray(['state' => 'failed', 'durationMs' => 1200, 'error' => 'RuntimeException: token=[redacted]'])
            // Due at midnight today, last ran three days ago: late.
            ->and($tasks['licence:keys:prune']['late'])->toBeTrue()
            ->and($tasks['licence:keys:prune']['lastRun']['state'])->toBe('succeeded')
            ->and($tasks['alerts:digest']['timezone'])->toBe('Europe/London');
    });
});

test('sync problems group refused pushes, unknown entities and open conflicts by business, shop, till and entity', function () {
    $khan = $this->tenant('Khan Mini Mart');
    $patel = $this->tenant('Patel News', 1, 'PNW');
    $branch = $this->branchOf($khan);
    $register = $this->registerOf($branch, '01');

    DB::table('sync_branch_status')->insert([
        'id' => Ulid::new(), 'company_id' => $khan->id, 'branch_id' => $branch->id, 'last_push_at' => now()->subMinutes(5),
        'rows_day' => '2026-11-08', 'rows_accepted_today' => 40, 'rows_rejected_today' => 3, 'last_error_at' => now()->subMinutes(5),
        'last_error_code' => 'sync.row_rejected', 'last_error_message' => 'Sale 01K5 has a total with 3 decimal places. apiKey=sk_live_ABCDEFGHIJKLMNOPQRSTUV',
        'last_rejected_key' => 'Sale:01K5VB0000000000000000S001:1', 'last_register_id' => $register->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach (['01K5VB0000000000000000U001', '01K5VB0000000000000000U002'] as $id) {
        DB::table('till_unknown_rows')->insert([
            'company_id' => $patel->id, 'branch_id' => $this->branchOf($patel, 'PNW')->id, 'register_id' => null, 'entity' => 'LoyaltyCard',
            'entity_id' => $id, 'op' => 'U', 'version' => 1, 'seq' => 1, 'at' => now()->subHours(30), 'payload' => '{}', 'received_at' => now()->subHours(30),
        ]);
    }
    // Older than the 7-day window: not listed.
    DB::table('till_unknown_rows')->insert([
        'company_id' => $patel->id, 'branch_id' => $this->branchOf($patel, 'PNW')->id, 'register_id' => null, 'entity' => 'OldThing',
        'entity_id' => '01K5VB0000000000000000U003', 'op' => 'U', 'version' => 1, 'seq' => 1, 'at' => now()->subDays(9), 'payload' => '{}', 'received_at' => now()->subDays(9),
    ]);
    DB::table('sync_conflicts')->insert([
        'id' => Ulid::new(), 'company_id' => $khan->id, 'branch_id' => $branch->id, 'entity' => 'Product', 'entity_id' => '01K5VB0000000000000000P001',
        'kind' => 'hubEditNewer', 'incoming_version' => 2, 'status' => 'open', 'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2),
    ]);

    $this->actingAs($this->admin(AdminRole::Support), 'admin')->get('/admin/sync-jobs')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('syncProblems.rows', 3)
        ->where('syncProblems.rows.0.kind', 'refused')
        ->where('syncProblems.rows.0.business', ['id' => $khan->id, 'name' => 'Khan Mini Mart'])
        ->where('syncProblems.rows.0.shop', 'Leeds (LDS)')
        ->where('syncProblems.rows.0.till', $register->name.' (01)')
        ->where('syncProblems.rows.0.entity', 'Sale')
        ->where('syncProblems.rows.0.count', 3)
        ->where('syncProblems.rows.0.detail', fn (string $detail) => str_contains($detail, 'apiKey=[redacted]') && ! str_contains($detail, 'sk_live'))
        ->where('syncProblems.rows.1.kind', 'conflict')
        ->where('syncProblems.rows.1.detail', 'Edited on the portal since')
        ->where('syncProblems.rows.2.kind', 'unknown')
        ->where('syncProblems.rows.2.entity', 'LoyaltyCard')
        ->where('syncProblems.rows.2.count', 2)
        ->where('syncProblems.rows.2.lastSeenAt', '2026-11-07T06:00:00Z')
        ->where('health.3.state', 'problem')
        ->where('health.3.value', '2')
    );
});

test('till traffic lists the most recent pushes and flags trading businesses quiet for more than 24 hours', function () {
    $khan = $this->tenant('Khan Mini Mart');
    $patel = $this->tenant('Patel News', 1, 'PNW');
    $gone = $this->tenant('Gone Stores', 1, 'GNE');
    $gone->forceFill(['status' => CompanyStatus::Cancelled])->saveQuietly();
    $status = fn ($company, string $code, $push, $pull = null) => DB::table('sync_branch_status')->insert([
        'id' => Ulid::new(), 'company_id' => $company->id, 'branch_id' => $this->branchOf($company, $code)->id, 'last_push_at' => $push, 'last_pull_at' => $pull,
        'rows_day' => '2026-11-08', 'rows_accepted_today' => 12, 'rows_rejected_today' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $status($khan, 'LDS', now()->subMinutes(3), now()->subMinute());
    $status($patel, 'PNW', now()->subHours(30));
    $status($gone, 'GNE', now()->subHours(50));

    $this->actingAs($this->admin(), 'admin')->get('/admin/sync-jobs')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('traffic.businesses', 3)
        ->has('traffic.recent', 3)
        ->where('traffic.recent.0.business.name', 'Khan Mini Mart')
        ->where('traffic.recent.0.lastPushAt', '2026-11-08T11:57:00Z')
        ->where('traffic.recent.0.lastPullAt', '2026-11-08T11:59:00Z')
        ->where('traffic.recent.0.acceptedToday', 12)
        ->where('traffic.recent.0.quiet', false)
        ->where('traffic.recent.1.business.name', 'Patel News')
        ->where('traffic.recent.1.quiet', true)
        ->where('traffic.quietTotal', 1)
        ->where('traffic.quiet.0.business.name', 'Patel News')
        ->where('traffic.quietHours', 24)
    );
});
