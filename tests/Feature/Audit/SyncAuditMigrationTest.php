<?php

use App\Domain\Shared\Support\Ulid;
use App\Domain\Sync\Actions\IssueSyncKey;
use App\Domain\Sync\Enums\SyncKeySource;
use App\Domain\Sync\Models\SyncKey;
use App\Domain\Sync\Support\SyncKeySecret;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Tenants\TenantTestHelpers;
use Tests\Feature\TillData\TillFixtures;
use Tests\Support\SsposDocs;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class)->group('audit-sync');

/*
 * Sync audit 2026-10-11 (docs/audit/2026-10-11-sync.md): `cloud/migrate` and the history upload (contract §17.8).
 * Each test proves one finding (SYNC-n) and FAILS until it is fixed. Setup as tests/Feature/Sync/CloudMigrationTest.
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
    $this->push = fn (string $key, string $upload, array $rows) => $this->postJson('/api/v1/sync/push', $rows, ($this->headers)([...($this->bearer)($key), 'X-SSPOS-Sync-Mode' => 'initial', 'X-SSPOS-Upload-Id' => $upload]));
    $this->complete = fn (string $key, array $body) => $this->postJson('/api/v1/cloud/migrate/complete', $body, ($this->headers)(($this->bearer)($key)));
    $this->department = function (int $seq) {
        $id = '01K5TD0000000000000000'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);

        return TillFixtures::envelope('Department', Pull::payload('Department', $id, ['name' => "Dept {$seq}", 'companyId' => $this->ids['companyId']]), $seq, ['companyId' => $this->ids['companyId'], 'branchId' => '']);
    };
});

test('SYNC-5: Connect with a sync key that was just replaced answers with a key that keeps working', function () {
    $replaced = $this->syncKey;
    app(IssueSyncKey::class)->handle($this->branch, SyncKeySource::Admin); // staff generated a second key

    $reply = ($this->migrate)(['activationCode' => $replaced])->assertOk();
    $apiKey = (string) $reply->json('apiKey');
    $held = SyncKey::withoutCompanyScope()->whereIn('key_hash', SyncKeySecret::hashCandidates($apiKey))->sole();

    // Expected: the till is given the branch's current key. Actual: the replaced key is echoed back as `apiKey`; it
    // dies 7 days after it was replaced (401 auth.key_revoked) and validate never re-sends one to a till that syncs.
    expect($held->isCurrent())->toBeTrue();
});

test('SYNC-6: a history row the portal acknowledges but never stores does not keep the upload open for ever', function () {
    $reply = ($this->migrate)()->assertOk();
    [$key, $upload] = [$reply->json('apiKey'), $reply->json('upload.uploadId')];

    // Seq 2: a setting the portal's deny-list skips (per-user screen setting; tills up to 0.1.31 sent these as company
    // settings). The push acknowledges it (200, acknowledgedSeq 3) but the ledger never records seq 2.
    $setting = [
        'seq' => 2, 'entity' => 'Setting', 'entityId' => 'D9FR992FKMEN9BGPG7H9MME95C', 'op' => 'I', 'version' => 2,
        'companyId' => $this->ids['companyId'], 'branchId' => '', 'registerId' => '', 'at' => '2026-09-23T09:53:12Z',
        'payload' => ['scope' => 'company', 'scopeId' => $this->ids['companyId'], 'key' => 'grid.layout.sales', 'value' => '{}', 'updatedAt' => '2026-09-23T09:53:12Z'],
    ];
    ($this->push)($key, $upload, [($this->department)(1), $setting, ($this->department)(3)])->assertOk()->assertJsonPath('acknowledgedSeq', 3);

    $answer = ($this->complete)($key, ['uploadId' => $upload, 'totalRows' => 2, 'highestSeq' => 3, 'rowCounts' => ['Department' => 2], 'snapshotChangeLogSeq' => 10])->assertOk();

    // Expected: complete (every row it sent was acknowledged). Actual: `incomplete`, resumeFromSeq 1 — the till resends
    // seq 2, it is skipped again, and the upload (which stops the till's normal push and pull) never closes.
    expect($answer->json('status'))->toBe('complete');
});
