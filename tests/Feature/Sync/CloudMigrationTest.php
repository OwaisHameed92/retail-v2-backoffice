<?php

use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LocalLicenceKey;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Sync\Actions\IssueSyncKey;
use App\Domain\Sync\Enums\CloudUploadStatus;
use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Enums\SyncKeySource;
use App\Domain\Sync\Models\CloudUpload;
use App\Domain\Sync\Models\IdMapping;
use App\Domain\Tenancy\Actions\AddBranch;
use App\Domain\Tenancy\Data\BranchDetails;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Tenants\TenantTestHelpers;
use Tests\Feature\TillData\TillFixtures;
use Tests\Support\SsposDocs;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class);

/*
 * Module 2.8: a shop moving to the cloud (contract v1.4.1 §17.8): `cloud/migrate` → `sync/push` in initial mode →
 * `cloud/migrate/complete`. The migrate and complete samples are replayed here; ContractReplyGuard checks every reply.
 */
beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    $this->withSigningKey();
    [$this->company, $this->licence] = $this->keyedTenant();
    $this->branch = $this->branchOf($this->company);
    $this->syncKey = app(IssueSyncKey::class)->handle($this->branch, SyncKeySource::Admin);
    $this->request = [...SsposDocs::sample('migrate-request.json'), 'activationCode' => $this->syncKey];
    $this->ids = ['companyId' => $this->request['company']['id'], 'branchId' => $this->request['branch']['id'], 'registerId' => $this->request['registers'][0]['registerId']];

    $this->headers = fn (array $extra = []) => [
        'X-SSPOS-Contract' => '1', 'X-SSPOS-App-Version' => '3.0.412', 'X-SSPOS-Store-Protocol' => '3',
        'X-SSPOS-Install-Id' => $this->request['installId'], 'X-SSPOS-Company-Id' => $this->ids['companyId'],
        'X-SSPOS-Branch-Id' => $this->ids['branchId'], 'X-SSPOS-Register-Id' => $this->ids['registerId'], 'Idempotency-Key' => Ulid::new(), ...$extra,
    ];
    $this->migrate = fn (array $body = [], array $headers = []) => $this->postJson('/api/v1/cloud/migrate', [...$this->request, ...$body], ($this->headers)($headers));
    $this->bearer = fn (string $key) => ['Authorization' => 'Bearer '.$key];
    $this->push = function (string $key, string $upload, array $rows, array $headers = []) {
        return $this->postJson('/api/v1/sync/push', $rows, ($this->headers)([...($this->bearer)($key), 'X-SSPOS-Sync-Mode' => 'initial', 'X-SSPOS-Upload-Id' => $upload, ...$headers]));
    };
    $this->complete = fn (string $key, array $body) => $this->postJson('/api/v1/cloud/migrate/complete', $body, ($this->headers)(($this->bearer)($key)));
    $this->history = fn (int $from, int $count) => array_map(function (int $seq) {
        $id = '01K5TD0000000000000000'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);

        return TillFixtures::envelope('Department', Pull::payload('Department', $id, ['name' => "Dept {$seq}", 'companyId' => $this->ids['companyId']]), $seq, ['companyId' => $this->ids['companyId'], 'branchId' => '']);
    }, range($from, $from + $count - 1));
});

test('migrate-request.json with the shop\'s sync key: ids adopted, main till licensed, days carried over (migrate-reply.adopted.json)', function () {
    $reply = ($this->migrate)()->assertOk();
    $sample = SsposDocs::sample('migrate-reply.adopted.json');

    expect(array_keys($reply->json()))->toEqualCanonicalizing(array_keys($sample))
        ->and($reply->json('companyId'))->toBe($this->ids['companyId'])
        ->and($reply->json('branchId'))->toBe($this->ids['branchId'])
        ->and($reply->json('idMapping.company'))->toBe(['localId' => $this->ids['companyId'], 'portalId' => $this->company->id, 'action' => 'adopted'])
        ->and($reply->json('idMapping.branch'))->toBe(['localId' => $this->ids['branchId'], 'portalId' => $this->branch->id, 'action' => 'adopted'])
        ->and($reply->json('registers'))->toBe([['registerId' => $this->ids['registerId'], 'seat' => 'allowed']])
        ->and($reply->json('apiKey'))->toBe($this->syncKey)                // the sync key is the bearer (SIMPLE-SETUP §3)
        ->and($reply->json('hubUrl'))->toStartWith('https://')
        ->and($reply->json('upload'))->toMatchArray(['mode' => 'initial', 'maxRowsPerBatch' => 5000, 'resumeFromSeq' => 0])
        ->and($reply->json('carriedOverDays'))->toBe(177)
        ->and($reply->json('licence.expiresAt'))->toBe('2027-03-31T23:59:59Z')
        ->and($this->verifyToken($reply)->get('installCode'))->toBe($this->request['installCode']);

    $licence = Licence::withoutCompanyScope()->where('device_id', $this->request['installId'])->sole();
    $upload = CloudUpload::withoutCompanyScope()->sole();

    expect($licence->id)->toBe($this->licence->id)                           // the main till's key
        ->and($upload->only(['id', 'branch_id', 'expected_rows', 'snapshot_change_log_seq', 'local_licence_id', 'carried_over_days']))->toBe([
            'id' => $reply->json('upload.uploadId'), 'branch_id' => $this->branch->id, 'expected_rows' => 159306,
            'snapshot_change_log_seq' => 212806, 'local_licence_id' => '01K5M1GR8T000000000000Y002', 'carried_over_days' => 177,
        ])
        ->and(IdMapping::withoutCompanyScope()->where('till_id', $this->ids['registerId'])->value('portal_id'))->toBe($this->licence->register_id)
        ->and(LocalLicenceKey::query()->sole()->only(['licence_id', 'install_code', 'reported_via', 'branch_id']))
        ->toBe(['licence_id' => '01K5M1GR8T000000000000Y002', 'install_code' => '0M01-TFG6', 'reported_via' => 'migrate', 'branch_id' => $this->branch->id]);
});

test('a second branch of a business the portal knows is aliased (migrate-reply.aliased.json)', function () {
    $this->activateTill()->assertOk();   // Leeds' first till adopted TILL_COMPANY as this business's company id
    $this->company->forceFill(['multi_branch' => true, 'max_branches' => 3])->save();
    $bradford = app(AddBranch::class)->handle($this->company, new BranchDetails('BFD', 'Bradford'), 1);
    $key = app(IssueSyncKey::class)->handle($bradford, SyncKeySource::Admin);

    $reply = ($this->migrate)(['activationCode' => $key])->assertOk();

    expect(array_keys($reply->json()))->toEqualCanonicalizing(array_keys(SsposDocs::sample('migrate-reply.aliased.json')))
        ->and($reply->json('idMapping.company'))->toBe(['localId' => $this->ids['companyId'], 'portalId' => $this->company->id, 'action' => 'aliased'])
        ->and($reply->json('idMapping.branch'))->toBe(['localId' => $this->ids['branchId'], 'portalId' => $bradford->id, 'action' => 'adopted'])
        ->and($reply->json('companyId'))->toBe($this->ids['companyId'])
        ->and($reply->json('licence.branchId'))->toBe($bradford->id);
});

test('initial upload → complete: incomplete with missing counts, then complete; the upload closes and push resumes after the snapshot', function () {
    $reply = ($this->migrate)()->assertOk();
    [$key, $upload] = [$reply->json('apiKey'), $reply->json('upload.uploadId')];
    $body = ['uploadId' => $upload, 'totalRows' => 5, 'highestSeq' => 5, 'rowCounts' => ['Department' => 5], 'snapshotChangeLogSeq' => 900];

    ($this->push)($key, $upload, ($this->history)(1, 3))->assertOk()->assertJsonPath('acknowledgedSeq', 3);

    $incomplete = ($this->complete)($key, $body)->assertOk();
    expect($incomplete->json())->toMatchArray(['status' => 'incomplete', 'received' => 3, 'resumeFromSeq' => 3, 'pushResumesAfterSeq' => 900])
        ->and($incomplete->json('missing'))->toBe([['entity' => 'Department', 'expected' => 5, 'received' => 3]])
        ->and(array_keys($incomplete->json()))->toEqualCanonicalizing(array_keys(SsposDocs::sample('migrate-complete-reply.incomplete.json')));

    // A retried migrate gets the same upload, resuming after what we hold.
    expect(($this->migrate)()->assertOk()->json('upload'))->toMatchArray(['uploadId' => $upload, 'resumeFromSeq' => 3]);

    ($this->push)($key, $upload, ($this->history)(4, 2))->assertOk();
    $complete = ($this->complete)($key, $body)->assertOk();

    expect($complete->json())->toMatchArray(['status' => 'complete', 'received' => 5, 'resumeFromSeq' => 5, 'missing' => [], 'pushResumesAfterSeq' => 900])
        ->and(array_keys($complete->json()))->toEqualCanonicalizing(array_keys(SsposDocs::sample('migrate-complete-reply.json')))
        ->and(CloudUpload::withoutCompanyScope()->sole()->status)->toBe(CloudUploadStatus::Complete)
        ->and(DB::table('sync_branch_status')->where('branch_id', $this->branch->id)->value('last_acknowledged_seq'))->toBe(900)
        ->and(DB::table('departments')->where('company_id', $this->company->id)->count())->toBe(5);

    // Closed: complete again says complete; another initial batch is refused; normal push goes on.
    ($this->complete)($key, $body)->assertOk()->assertJsonPath('status', 'complete');
    ($this->push)($key, $upload, ($this->history)(6, 1))->assertStatus(409)->assertJsonPath('code', 'migrate.upload_closed');
});

test('migrate-complete-request.json replays: every entity it counts is reported missing on an empty upload', function () {
    $reply = ($this->migrate)()->assertOk();
    $sample = [...SsposDocs::sample('migrate-complete-request.json'), 'uploadId' => $reply->json('upload.uploadId')];

    $answer = ($this->complete)($reply->json('apiKey'), $sample)->assertOk();

    expect($answer->json('status'))->toBe('incomplete')
        ->and(array_column($answer->json('missing'), 'entity'))->toEqualCanonicalizing(array_keys($sample['rowCounts']));
});

test('upload errors: unknown or another shop\'s upload 404, another PC 403 on complete', function () {
    $reply = ($this->migrate)()->assertOk();
    [$key, $upload] = [$reply->json('apiKey'), $reply->json('upload.uploadId')];

    ($this->push)($key, Ulid::new(), ($this->history)(1, 1))->assertNotFound()->assertJsonPath('code', 'migrate.upload_not_found');
    ($this->complete)($key, ['uploadId' => Ulid::new(), 'totalRows' => 0, 'highestSeq' => 0, 'rowCounts' => [], 'snapshotChangeLogSeq' => 0])
        ->assertNotFound()->assertJsonPath('code', 'migrate.upload_not_found');

    $this->postJson('/api/v1/cloud/migrate/complete', ['uploadId' => $upload, 'totalRows' => 0, 'highestSeq' => 0, 'rowCounts' => [], 'snapshotChangeLogSeq' => 0],
        ($this->headers)([...($this->bearer)($key), 'X-SSPOS-Install-Id' => self::OTHER_INSTALL]))->assertForbidden()->assertJsonPath('code', 'device.not_main_till');
    $this->postJson('/api/v1/cloud/migrate/complete', ['uploadId' => $upload], ($this->headers)())->assertStatus(401)->assertJsonPath('code', 'auth.invalid_key');
});

test('code errors: unknown 404, revoked 410, a second PC for the same shop 409 (error.branch-already-linked.409.json), wrong codes limited', function () {
    ($this->migrate)(['activationCode' => 'SSK-0000-0000-0000-0000-0000-0000-0000-0000'])->assertNotFound()->assertJsonPath('code', 'activation.code_not_found');

    ($this->migrate)()->assertOk();
    $second = ($this->migrate)(['installId' => self::OTHER_INSTALL, 'installCode' => self::OTHER_CODE], ['X-SSPOS-Install-Id' => self::OTHER_INSTALL])
        ->assertStatus(409)->assertJsonPath('code', 'device.branch_already_linked');
    expect(array_keys($second->json('details')))->toBe(array_keys(SsposDocs::sample('error.branch-already-linked.409.json')['details']));

    DB::table('sync_keys')->update(['revoked_at' => now()]);
    ($this->migrate)()->assertStatus(410)->assertJsonPath('code', 'activation.code_expired');

    config(['licence.api.rate_limits.activate_per_ip_per_hour' => 100]);
    foreach (range(1, 3) as $i) {
        ($this->migrate)(['activationCode' => 'SSK-0000-0000-0000-0000-0000-0000-0000-000'.$i]);
    }
    ($this->migrate)(['activationCode' => 'nope-nope'])->assertStatus(429)->assertJsonPath('code', 'activation.too_many_attempts');
});

test('a till holding another business\'s data gets 409 migrate.already_migrated; a forged local key 422; tenant isolation', function () {
    [$other] = $this->keyedTenant('Other Stores', 1, 'OTH', 'SSP-4HWC-J6ZB-81ME-QV5H');
    IdMapping::withoutCompanyScope()->create(['kind' => IdKind::Branch, 'till_id' => $this->ids['branchId'], 'portal_id' => $this->branchOf($other, 'OTH')->id, 'company_id' => $other->id, 'branch_id' => $this->branchOf($other, 'OTH')->id, 'action' => 'adopted']);

    ($this->migrate)()->assertStatus(409)->assertJsonPath('code', 'migrate.already_migrated');
    IdMapping::withoutCompanyScope()->where('company_id', $other->id)->delete();

    [$p, $payload, $sig] = explode('.', $this->request['localLicenceToken']);
    ($this->migrate)(['localLicenceToken' => "{$p}.{$payload}.".substr($sig, 0, 10).($sig[10] === 'A' ? 'B' : 'A').substr($sig, 11)])
        ->assertStatus(422)->assertJsonPath('code', 'licence.bad_signature');

    expect(CloudUpload::withoutCompanyScope()->count())->toBe(0)
        ->and(Licence::withoutCompanyScope()->whereNotNull('device_id')->count())->toBe(0);
});

test('a portal licence key with the dashboard works as the code (a local shop that entered a portal key); without it 403', function () {
    $this->activateTill(install: $this->request['installId'], code: $this->request['installCode'])->assertOk();
    $this->licence->forceFill(['features' => ['cloud_sync']])->save();

    $reply = ($this->migrate)(['activationCode' => self::KEY, 'localLicenceToken' => null])->assertOk();
    expect($reply->json('apiKey'))->toStartWith('SSK-')->not->toBe($this->syncKey)
        ->and($reply->json('carriedOverDays'))->toBe(0);

    $this->licence->forceFill(['features' => []])->save();
    CloudUpload::withoutCompanyScope()->delete();
    ($this->migrate)(['activationCode' => self::KEY, 'localLicenceToken' => null])->assertForbidden()->assertJsonPath('code', 'licence.not_active');
});

test('ANSWERS-2026-09-30-portal points 4 and 5: a portal licence key not yet activated → 409 migrate.activate_first; on another PC → 409 activation.code_used', function () {
    $this->licence->forceFill(['features' => ['cloud_sync']])->save();

    ($this->migrate)(['activationCode' => self::KEY, 'localLicenceToken' => null])->assertStatus(409)
        ->assertJsonPath('code', 'migrate.activate_first')
        ->assertJsonPath('message', 'Enter this licence key under Settings → Licence first.');

    $this->activateTill(install: self::OTHER_INSTALL, code: self::OTHER_CODE)->assertOk();
    ($this->migrate)(['activationCode' => self::KEY, 'localLicenceToken' => null])->assertStatus(409)->assertJsonPath('code', 'activation.code_used');

    expect(CloudUpload::withoutCompanyScope()->count())->toBe(0);
});
