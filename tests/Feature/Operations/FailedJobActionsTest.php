<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class);

/*
 * Admin "Sync & jobs" → Failed jobs: list, detail (secrets removed), retry (queue:retry) and delete (queue:forget),
 * both audited.
 */

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:00', 'UTC'));
    $this->support = $this->admin(AdminRole::Support);
});

/** A queued model as Laravel serialises it (ModelIdentifier). */
function modelProperty(string $name, string $class, string $id): string
{
    return 's:'.strlen($name).':"'.$name.'";O:45:"Illuminate\Contracts\Database\ModelIdentifier":5:{s:5:"class";s:'.strlen($class).':"'.$class
        .'";s:2:"id";s:'.strlen($id).':"'.$id.'";s:9:"relations";a:0:{}s:10:"connection";s:6:"sqlite";s:15:"collectionClass";N;}';
}

/**
 * A failed job row as the database-uuids failer writes it; the command is a serialised job holding a Company.
 *
 * @param  list<string>  $extra  more serialised properties, e.g. 's:4:"name";s:4:"Khan";'
 */
function failedJob(array $overrides = [], ?Company $company = null, array $extra = []): string
{
    $uuid = $overrides['uuid'] ?? (string) Str::uuid();
    $props = $company === null ? $extra : [modelProperty('company', Company::class, $company->id), ...$extra];
    $class = 'App\Domain\Billing\Jobs\SendIt';
    $command = 'O:'.strlen($class).':"'.$class.'":'.count($props).':{'.implode('', $props).'}';

    DB::table('failed_jobs')->insert(array_merge([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode([
            'uuid' => $uuid, 'displayName' => 'App\Domain\Billing\Jobs\SendIt', 'job' => 'Illuminate\Queue\CallQueuedHandler@call',
            'maxTries' => 3, 'timeout' => 60, 'attempts' => 3, 'data' => ['commandName' => 'App\Domain\Billing\Jobs\SendIt', 'command' => $command],
        ]),
        'exception' => "RuntimeException: GoCardless refused the request (Authorization: Bearer live_abcdefghijklmnopqrstuvwxyz123456) password=hunter22\n#0 /var/www/app/Jobs/SendIt.php(42): run()\n#1 {main}",
        'failed_at' => now()->subMinutes(10),
    ], $overrides));

    return $uuid;
}

test('the list shows job, queue, failure time, the first error line without secrets and the business from the payload', function () {
    $khan = $this->tenant('Khan Mini Mart');
    $uuid = failedJob([], $khan);
    failedJob(['payload' => 'not json at all', 'queue' => 'mail', 'failed_at' => now()->subHours(30)]);

    $this->actingAs($this->support, 'admin')->get('/admin/sync-jobs')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('failedJobs.meta.total', 2)
        ->where('failedJobs.data.0.uuid', $uuid)
        ->where('failedJobs.data.0.job', 'SendIt')
        ->where('failedJobs.data.0.jobClass', 'App\Domain\Billing\Jobs\SendIt')
        ->where('failedJobs.data.0.queue', 'default')
        ->where('failedJobs.data.0.failedAt', '2026-11-08T11:50:00Z')
        ->where('failedJobs.data.0.attempts', 3)
        ->where('failedJobs.data.0.business', ['id' => $khan->id, 'name' => 'Khan Mini Mart'])
        ->where('failedJobs.data.0.error', 'RuntimeException: GoCardless refused the request (Authorization: [redacted]) password=[redacted]')
        // An odd payload never breaks the page.
        ->where('failedJobs.data.1.job', 'Unknown job')
        ->where('failedJobs.data.1.business', null)
        ->where('failedFilters.queues', ['default', 'mail'])
        ->where('health.2.value', '1')
        ->where('health.2.state', 'warning')
    );

    $this->get('/admin/sync-jobs?queue=mail')->assertInertia(fn (Assert $page) => $page->where('failedJobs.meta.total', 1)->where('failedFilters.queue', 'mail'));
    $this->get('/admin/sync-jobs?search=GoCardless')->assertInertia(fn (Assert $page) => $page->where('failedJobs.meta.total', 2));
});

test('a business is found through a tenant-owned model or a companyId property when no Company was queued', function () {
    $khan = $this->tenant('Khan Mini Mart');
    $branch = $this->branchOf($khan);
    $viaModel = failedJob([], null, [modelProperty('branch', Branch::class, $branch->id)]);
    $viaProperty = failedJob(['failed_at' => now()->subMinutes(20)], null, ['s:9:"companyId";s:26:"'.$khan->id.'";']);

    $this->actingAs($this->support, 'admin')->get('/admin/sync-jobs')->assertInertia(fn (Assert $page) => $page
        ->where('failedJobs.data.0.uuid', $viaModel)
        ->where('failedJobs.data.0.business.name', 'Khan Mini Mart')
        ->where('failedJobs.data.1.uuid', $viaProperty)
        ->where('failedJobs.data.1.business.name', 'Khan Mini Mart'));
});

test('the detail sends the whole exception with secrets removed and the queued records, never the raw payload', function () {
    $khan = $this->tenant('Khan Mini Mart');
    $uuid = failedJob([], $khan, ['s:8:"password";s:8:"hunter22";']);

    $response = $this->actingAs($this->support, 'admin')->getJson(route('admin.sync-jobs.failed-jobs.show', $uuid))->assertOk()
        ->assertJsonPath('uuid', $uuid)
        ->assertJsonPath('maxTries', 3)
        ->assertJsonPath('timeout', 60)
        ->assertJsonPath('records', [['type' => 'Company', 'id' => $khan->id]])
        ->assertJsonPath('business.name', 'Khan Mini Mart')
        ->assertJsonPath('exceptionTruncated', false)
        ->assertJsonMissingPath('payload');

    $body = $response->getContent();
    expect($response->json('exception'))->toContain('#0 /var/www/app/Jobs/SendIt.php(42): run()')
        ->and($body)->not->toContain('hunter22')
        ->and($body)->not->toContain('live_abcdefghijklmnopqrstuvwxyz123456');

    $this->getJson(route('admin.sync-jobs.failed-jobs.show', (string) Str::uuid()))->assertNotFound();
});

test('retry sends the job back to its queue like queue:retry, removes it from the list and audits it', function () {
    $uuid = failedJob();
    $other = failedJob();

    $this->actingAs($this->support, 'admin')->from('/admin/sync-jobs')
        ->post(route('admin.sync-jobs.failed-jobs.retry'), ['uuids' => [$uuid]])
        ->assertRedirect('/admin/sync-jobs')->assertSessionHas('success', '1 job sent back to the queue.');

    expect(DB::table('failed_jobs')->pluck('uuid')->all())->toBe([$other])
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(json_decode((string) DB::table('jobs')->value('payload'), true))->toMatchArray(['uuid' => $uuid, 'attempts' => 0]);

    $audit = AuditLog::query()->where('action', 'failed_job.retried')->sole();
    expect($audit->actor_id)->toBe($this->support->id)
        ->and($audit->meta)->toMatchArray(['uuid' => $uuid, 'job' => 'App\Domain\Billing\Jobs\SendIt', 'queue' => 'default', 'connection' => 'database']);
});

test('retry all shown sends every given job back; jobs already gone are reported', function () {
    $uuids = [failedJob(), failedJob(), failedJob()];

    $this->actingAs($this->support, 'admin')->from('/admin/sync-jobs')
        ->post(route('admin.sync-jobs.failed-jobs.retry'), ['uuids' => $uuids])
        ->assertSessionHas('success', '3 jobs sent back to the queue.');

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(3)
        ->and(AuditLog::query()->where('action', 'failed_job.retried')->count())->toBe(3);

    $this->post(route('admin.sync-jobs.failed-jobs.retry'), ['uuids' => $uuids])->assertSessionHas('error', 'These jobs are no longer in the failed list.');
});

test('a job whose payload cannot be read back is not retried: it stays in the list and nothing is audited', function () {
    $broken = failedJob(['payload' => json_encode(['displayName' => 'App\Jobs\Broken', 'data' => ['command' => 'O:3:"Foo":9:{']])]);

    $this->actingAs($this->support, 'admin')->from('/admin/sync-jobs')
        ->post(route('admin.sync-jobs.failed-jobs.retry'), ['uuids' => [$broken]])
        ->assertSessionHas('error', '1 job could not be retried and is still in the list.');

    expect(DB::table('failed_jobs')->pluck('uuid')->all())->toBe([$broken])
        ->and(AuditLog::query()->count())->toBe(0);
});

test('delete removes the job for good without running it, and audits it', function () {
    $uuid = failedJob();

    $this->actingAs($this->support, 'admin')->from('/admin/sync-jobs')
        ->delete(route('admin.sync-jobs.failed-jobs.destroy'), ['uuids' => [$uuid]])
        ->assertRedirect('/admin/sync-jobs')->assertSessionHas('success', 'Failed job deleted.');

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'failed_job.deleted')->sole()->meta)->toMatchArray(['uuid' => $uuid, 'queue' => 'default']);
});

test('retry and delete validate the uuids', function () {
    $this->actingAs($this->support, 'admin')->from('/admin/sync-jobs')
        ->post(route('admin.sync-jobs.failed-jobs.retry'), ['uuids' => []])->assertSessionHasErrors('uuids');
    $this->delete(route('admin.sync-jobs.failed-jobs.destroy'), ['uuids' => ['../../etc']])->assertSessionHasErrors('uuids.0');
    $this->post(route('admin.sync-jobs.failed-jobs.retry'), ['uuids' => array_map(fn () => (string) Str::uuid(), range(1, 101))])->assertSessionHasErrors('uuids');

    expect(AuditLog::query()->count())->toBe(0);
});
