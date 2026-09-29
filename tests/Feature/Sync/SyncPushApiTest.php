<?php

use App\Domain\Sync\Models\SyncBranchStatus;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\Sale;
use App\Domain\TillData\Models\SaleLine;
use App\Domain\TillData\Models\StockMovement;
use App\Domain\TillData\Sync\SyncRowIds;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/** Module 2.2: `POST /api/v1/sync/push` (contract v1.4.1 §3, §7, §9, §17.8, §19). */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->status = fn ($branch) => SyncBranchStatus::withoutCompanyScope()->where('branch_id', $branch->id)->first();
});

test('the three push samples replay into the right company, branch and tills, with replies valid per push-reply', function () {
    $this->freezeSecond();
    $s = $this->sync;

    $leeds = $s->push(TillFixtures::sample('push-request.json'))->assertOk()->assertHeader('X-SSPOS-Contract', '1');
    expect(TillFixtures::ack($leeds->json()))->toBe(TillFixtures::ack(TillFixtures::sample('push-reply.json')))
        ->and($leeds->json('receivedAt'))->toBe(now('UTC')->format('Y-m-d\TH:i:s\Z'))
        ->and(SyncApiFixtures::schemaErrors($leeds, 'push-reply.schema.json'))->toBe([]);

    $till2 = $s->push(TillFixtures::sample('push-request.second-till.json'))->assertOk();
    expect(TillFixtures::ack($till2->json()))->toBe(['acknowledgedSeq' => 18257, 'accepted' => 18])
        ->and(SyncApiFixtures::schemaErrors($till2, 'push-reply.schema.json'))->toBe([]);

    $bradford = $s->push(TillFixtures::sample('push-request.second-branch.json'), bradford: true)->assertOk();
    expect(TillFixtures::ack($bradford->json()))->toBe(['acknowledgedSeq' => 5127, 'accepted' => 8])
        ->and(SyncApiFixtures::schemaErrors($bradford, 'push-reply.schema.json'))->toBe([]);

    $sales = Sale::withoutCompanyScope()->get()->keyBy('id');
    expect($sales)->toHaveCount(4)
        ->and($sales->pluck('company_id')->unique()->all())->toBe([$s->company->id])
        ->and($sales['01K5VB000000000SR001000482']->branch_id)->toBe($s->leeds->id)
        ->and($sales['01K5VB000000000SR001000482']->register_id)->toBe($s->tills[TillFixtures::TILL_1]->id)
        ->and($sales->firstWhere('register_id', $s->tills[TillFixtures::TILL_2]->id)?->branch_id)->toBe($s->leeds->id)
        ->and($sales->firstWhere('register_id', $s->tills[TillFixtures::BRADFORD_TILL]->id)?->branch_id)->toBe($s->bradford->id)
        ->and(SaleLine::withoutCompanyScope()->whereNotIn('branch_id', [$s->leeds->id, $s->bradford->id])->count())->toBe(0)
        ->and(StockMovement::withoutCompanyScope()->where('branch_id', $s->bradford->id)->count())->toBe(2)
        ->and(DB::table('sync_applied_changes')->where('branch_id', $s->leeds->id)->count())->toBe(27);
});

test('a plain JSON body is accepted as well as gzip', function () {
    $reply = $this->sync->push(TillFixtures::sample('push-request.json'), gzip: false)->assertOk();

    expect(TillFixtures::ack($reply->json()))->toBe(TillFixtures::ack(TillFixtures::sample('push-reply.json')));

    expect(Sale::withoutCompanyScope()->count())->toBe(1);
});

test('the same batch twice gives the same reply and no duplicates; an Idempotency-Key replays the stored reply', function () {
    $batch = TillFixtures::sample('push-request.json');
    $first = $this->sync->push($batch)->assertOk();
    $second = $this->sync->push($batch)->assertOk();

    expect($second->json())->toBe($first->json())
        ->and(Sale::withoutCompanyScope()->count())->toBe(1)
        ->and(SaleLine::withoutCompanyScope()->count())->toBe(2)
        ->and(DB::table('sync_applied_changes')->count())->toBe(9);

    $key = ['Idempotency-Key' => '01K6AAAAAAAAAAAAAAAAAAAAAA'];
    $tagged = $this->sync->push(TillFixtures::sample('push-request.second-till.json'), $key)->assertOk()->assertHeaderMissing('Idempotency-Replayed');
    $replay = $this->sync->push(TillFixtures::sample('push-request.second-till.json'), $key, gzip: false)->assertOk()->assertHeader('Idempotency-Replayed', 'true');

    expect($replay->json())->toBe($tagged->json())->and(Sale::withoutCompanyScope()->count())->toBe(3);

    $mismatch = $this->sync->push($batch, $key)->assertStatus(422)->assertJsonPath('code', 'request.idempotency_mismatch');
    expect(SyncApiFixtures::schemaErrors($mismatch, 'error-reply.schema.json'))->toBe([]);
});

test('a rejected row mid-batch: 200 with acknowledgedSeq before it; the rows after it are stored and come back as duplicates', function () {
    $batch = TillFixtures::sample('push-request.json');
    $batch[3]['payload']['amount'] = 'lots';   // seq 18234, SalePayment

    $reply = $this->sync->push($batch)->assertOk();

    expect(TillFixtures::ack($reply->json()))->toBe(['acknowledgedSeq' => 18233, 'accepted' => 8])
        ->and(SyncApiFixtures::schemaErrors($reply, 'push-reply.schema.json'))->toBe([])
        ->and(StockMovement::withoutCompanyScope()->count())->toBe(2);

    $status = ($this->status)($this->sync->leeds);
    expect($status->last_acknowledged_seq)->toBe(18233)
        ->and($status->rows_accepted_today)->toBe(8)
        ->and($status->rows_rejected_today)->toBe(1)
        ->and($status->last_error_code)->toBe('row.invalid')
        ->and($status->last_rejected_key)->toBe($batch[3]['key']);

    // The till resends from the rejected row: nothing before it can be acknowledged → 422 with its key.
    $again = $this->sync->push(array_slice($batch, 3))->assertStatus(422)
        ->assertJsonPath('code', 'row.invalid')
        ->assertJsonPath('rejectedKey', $batch[3]['key'])
        ->assertJsonPath('retryAfterSeconds', null);
    expect(SyncApiFixtures::schemaErrors($again, 'error-reply.schema.json'))->toBe([])
        ->and($again->json('message'))->toContain('SalePayment');

    // Fixed on the till: the rest are duplicates, and the batch is acknowledged in full.
    $fixed = $this->sync->push(TillFixtures::sample('push-request.json'))->assertOk();
    expect(TillFixtures::ack($fixed->json()))->toBe(['acknowledgedSeq' => 18239, 'accepted' => 9])
        ->and($fixed->json('receivedAt'))->toBe($reply->json('receivedAt'));
});

test('errors: 401, 403, 409, 400 and 413 use the error-reply envelope', function (string $case, int $status, string $code) {
    config(['sync.push.max_rows' => 5, 'sync.push.max_bytes' => $case === 'too many bytes' ? 4000 : 20000]);
    $batch = TillFixtures::sample('push-request.json');

    $response = match ($case) {
        'no key' => $this->sync->push($batch, ['Authorization' => null]),
        'unknown key' => $this->sync->push($batch, ['Authorization' => 'Bearer SSK-0000-0000-0000-0000-0000-0000-0000-0000']),
        'other branch header' => $this->sync->push($batch, ['X-SSPOS-Branch-Id' => TillFixtures::BRADFORD]),
        'no contract' => $this->sync->push($batch, ['X-SSPOS-Contract' => null]),
        'contract 2' => $this->sync->push($batch, ['X-SSPOS-Contract' => '2']),
        'no app version' => $this->sync->push($batch, ['X-SSPOS-App-Version' => null]),
        'no register' => $this->sync->push($batch, ['X-SSPOS-Register-Id' => null]),
        'bad json' => $this->sync->push('{"seq":', gzip: true),
        'broken gzip' => $this->sync->push(substr((string) gzencode(json_encode($batch)), 0, 200), ['Content-Encoding' => 'gzip'], gzip: false),
        'not a list' => $this->sync->push(['seq' => 1]),
        'empty' => $this->sync->push([]),
        'other encoding' => $this->sync->push($batch, ['Content-Encoding' => 'br'], gzip: false),
        'unknown mode' => $this->sync->push($batch, ['X-SSPOS-Sync-Mode' => 'full']),
        'initial without upload' => $this->sync->push($batch, ['X-SSPOS-Sync-Mode' => 'initial']),
        'bad idempotency key' => $this->sync->push($batch, ['Idempotency-Key' => 'abc']),
        'too many rows' => $this->sync->push([...$batch, ...$batch]),
        'too many bytes' => $this->sync->push(array_slice($batch, 0, 5)),
    };

    $response->assertStatus($status)->assertJsonPath('code', $code)->assertHeader('X-SSPOS-Contract', '1');
    expect(SyncApiFixtures::schemaErrors($response, 'error-reply.schema.json'))->toBe([])
        ->and(Sale::withoutCompanyScope()->count())->toBe(0);
})->with([
    ['no key', 401, 'auth.invalid_key'],
    ['unknown key', 401, 'auth.invalid_key'],
    ['other branch header', 403, 'auth.wrong_branch'],
    ['no contract', 409, 'contract.unsupported'],
    ['contract 2', 409, 'contract.unsupported'],
    ['no app version', 400, 'request.invalid'],
    ['no register', 400, 'request.invalid'],
    ['bad json', 400, 'request.invalid'],
    ['broken gzip', 400, 'request.invalid'],
    ['not a list', 400, 'request.invalid'],
    ['empty', 400, 'request.invalid'],
    ['other encoding', 400, 'request.invalid'],
    ['unknown mode', 400, 'request.invalid'],
    ['initial without upload', 400, 'request.invalid'],
    ['bad idempotency key', 400, 'request.invalid'],
    ['too many rows', 413, 'batch.too_large'],
    ['too many bytes', 413, 'batch.too_large'],
]);

test('a gzip bomb stops at the size limit', function () {
    config(['sync.push.max_bytes' => 1024 * 1024]);
    $bomb = (string) gzencode('['.str_repeat(' ', 20 * 1024 * 1024).']', 9);

    expect(strlen($bomb))->toBeLessThan(100_000);
    $this->sync->push($bomb, ['Content-Encoding' => 'gzip'], gzip: false)->assertStatus(413)->assertJsonPath('code', 'batch.too_large');
    expect(($this->status)($this->sync->leeds)->last_error_code)->toBe('batch.too_large');
});

test('429 rate.limited per sync key with Retry-After; another branch\'s key is not affected', function () {
    config(['sync.rate_limit_per_minute' => 12]);

    foreach (range(1, 12) as $i) {
        $this->sync->hello()->assertOk();
    }

    $limited = $this->sync->push(TillFixtures::sample('push-request.json'))->assertStatus(429)->assertJsonPath('code', 'rate.limited')->assertHeader('Retry-After');
    expect($limited->json('retryAfterSeconds'))->toBeGreaterThan(0)
        ->and(SyncApiFixtures::schemaErrors($limited, 'error-reply.schema.json'))->toBe([]);

    $this->sync->hello(bradford: true)->assertOk();
    $this->travel(61)->seconds();
    $this->sync->push(TillFixtures::sample('push-request.json'))->assertOk();
});

test('two pushes of one branch never interleave: 503 server.busy while the branch lock is held; other branches go on', function () {
    config(['sync.push.lock_wait_seconds' => 0]);
    $lock = Cache::lock('sync-push:branch:'.$this->sync->leeds->id, 60);
    expect($lock->get())->toBeTrue();

    $busy = $this->sync->push(TillFixtures::sample('push-request.json'))->assertStatus(503)
        ->assertJsonPath('code', 'server.busy')->assertJsonPath('retryAfterSeconds', 5)->assertHeader('Retry-After', '5');
    expect(SyncApiFixtures::schemaErrors($busy, 'error-reply.schema.json'))->toBe([])
        ->and(Sale::withoutCompanyScope()->count())->toBe(0);

    $this->sync->push(TillFixtures::sample('push-request.second-branch.json'), bradford: true)->assertOk();

    $lock->release();
    $this->sync->push(TillFixtures::sample('push-request.json'))->assertOk();
    expect(Cache::lock('sync-push:branch:'.$this->sync->leeds->id, 1)->get())->toBeTrue(); // released after the push
});

test('the same Idempotency-Key while its first push still runs: 409 request.in_progress, never stored', function () {
    $key = '01K5VB0000000000000000KEYS';
    $running = 'sync-push:running:'.$this->sync->leeds->id.':'.$key;
    Cache::add($running, true, 60);

    $reply = $this->sync->push(TillFixtures::sample('push-request.json'), ['Idempotency-Key' => $key])->assertStatus(409)
        ->assertJsonPath('code', 'request.in_progress')->assertJsonPath('retryAfterSeconds', 1)->assertHeader('Retry-After', '1');
    expect(SyncApiFixtures::schemaErrors($reply, 'error-reply.schema.json'))->toBe([])
        ->and(Sale::withoutCompanyScope()->count())->toBe(0);

    Cache::forget($running);
    $this->sync->push(TillFixtures::sample('push-request.json'), ['Idempotency-Key' => $key])->assertOk();
    expect(Cache::has($running))->toBeFalse();
});

test('sync_branch_status: last push, acknowledged seq, app version, register and counts for the London day', function () {
    $this->travelTo(now('UTC')->setTime(22, 30));
    $this->sync->push(TillFixtures::sample('push-request.json'), ['X-SSPOS-App-Version' => '3.0.500'])->assertOk();
    $this->sync->push(TillFixtures::sample('push-request.second-till.json'))->assertOk();

    $status = ($this->status)($this->sync->leeds);
    expect($status->company_id)->toBe($this->sync->company->id)
        ->and($status->last_push_at?->toIso8601ZuluString())->toBe(now('UTC')->toIso8601ZuluString())
        ->and($status->last_acknowledged_seq)->toBe(18257)
        ->and($status->rows_accepted_today)->toBe(27)
        ->and($status->rows_rejected_today)->toBe(0)
        ->and($status->last_app_version)->toBe('3.0.412')
        ->and($status->last_register_id)->toBe($this->sync->tills[TillFixtures::TILL_1]->id)
        ->and($status->last_till_register_id)->toBe(TillFixtures::TILL_1)
        ->and(($this->status)($this->sync->bradford))->toBeNull();

    // 23:30 UTC in summer is past midnight in London: a new day starts the counts again.
    $this->travel(2)->hours();
    $this->sync->push(TillFixtures::sample('push-request.json'))->assertOk();
    $status = ($this->status)($this->sync->leeds);
    expect($status->rows_accepted_today)->toBe(9)
        ->and($status->rows_day?->toDateString())->toBe(now('Europe/London')->toDateString());
});

test('tenant isolation: a branch key cannot write another branch or business', function () {
    $s = $this->sync;

    // Leeds's key with Bradford's rows: every row names another branch → rejected, nothing stored for Bradford.
    $response = $s->push(TillFixtures::sample('push-request.second-branch.json'))->assertStatus(422)->assertJsonPath('code', 'row.invalid');
    expect($response->json('rejectedKey'))->toBe('Sale:01K5VB000000000SR003000233:1')
        ->and(Sale::withoutCompanyScope()->count())->toBe(0)
        ->and(DB::table('stock_movements')->count())->toBe(0);

    // Bradford's key claiming to be Leeds → 403.
    $s->push(TillFixtures::sample('push-request.json'), ['X-SSPOS-Branch-Id' => TillFixtures::LEEDS], bradford: true)->assertStatus(403);

    // Another business's key: our ids in its rows are refused, nothing lands in our company.
    $other = new SyncApiFixtures($this, mapTillIds: false);
    $foreign = TillFixtures::sample('push-request.json');
    foreach ($foreign as &$change) {
        $change['companyId'] = $s->company->id;
        $change['branchId'] = $change['branchId'] === '' ? '' : $s->leeds->id;
        $change['registerId'] = $change['registerId'] === '' ? '' : $s->tills[TillFixtures::TILL_1]->id;
    }
    unset($change);

    $other->push($foreign, ['X-SSPOS-Company-Id' => $other->company->id, 'X-SSPOS-Branch-Id' => $other->leeds->id, 'X-SSPOS-Register-Id' => $other->tills[TillFixtures::TILL_1]->id])
        ->assertStatus(422)->assertJsonPath('code', 'row.invalid');
    expect(Sale::withoutCompanyScope()->count())->toBe(0);

    // Each business sees only its own branches' sync status.
    $seen = fn ($company) => app(CurrentCompany::class)->runAs($company, fn () => SyncBranchStatus::query()->pluck('branch_id')->all());
    expect($seen($other->company))->toBe([$other->leeds->id])
        ->and($seen($s->company))->toBe([$s->leeds->id]);
});

test('initial upload (X-SSPOS-Sync-Mode: initial): seqs 1…N are kept apart from the ChangeLog seqs', function () {
    $upload = '01K6ZZZZZZ0000000000000001';
    $initial = array_map(fn (array $change, int $i) => [...$change, 'seq' => $i + 1], TillFixtures::sample('push-request.json'), array_keys(TillFixtures::sample('push-request.json')));

    $reply = $this->sync->push($initial, ['X-SSPOS-Sync-Mode' => 'initial', 'X-SSPOS-Upload-Id' => $upload])->assertOk();
    expect(TillFixtures::ack($reply->json()))->toBe(['acknowledgedSeq' => 9, 'accepted' => 9]);

    // A delta push whose ChangeLog seqs are 1…18 is not mistaken for the upload's rows.
    $delta = array_map(fn (array $change, int $i) => [...$change, 'seq' => $i + 1], TillFixtures::sample('push-request.second-till.json'), array_keys(TillFixtures::sample('push-request.second-till.json')));
    expect(TillFixtures::ack($this->sync->push($delta)->assertOk()->json()))->toBe(['acknowledgedSeq' => 18, 'accepted' => 18]);

    expect(Sale::withoutCompanyScope()->count())->toBe(3)
        ->and(DB::table('sync_applied_changes')->where('stream', $upload)->count())->toBe(9)
        ->and(DB::table('sync_applied_changes')->where('stream', '')->count())->toBe(18);

    $status = ($this->status)($this->sync->leeds);
    expect($status->last_upload_id)->toBe($upload)->and($status->last_upload_seq)->toBe(9)->and($status->last_acknowledged_seq)->toBe(18);
});

test('receivedAt: UTC Z after the commit; a retry (duplicates or the same Idempotency-Key) gets the first time, never now', function () {
    $this->travelTo('2026-09-29 10:00:00');
    $batch = TillFixtures::sample('push-request.json');
    $first = $this->sync->push(array_slice($batch, 0, 5))->assertOk();
    expect($first->json('receivedAt'))->toBe('2026-09-29T10:00:00Z')
        ->and(SyncApiFixtures::schemaErrors($first, 'push-reply.schema.json'))->toBe([]);

    // The reply was lost; an hour later the till resends the same rows: the first time comes back.
    $this->travelTo('2026-09-29 11:00:00');
    expect($this->sync->push(array_slice($batch, 0, 5))->assertOk()->json('receivedAt'))->toBe('2026-09-29T10:00:00Z');

    // The resend carries more rows: those are stored now, so the batch's time is now.
    expect($this->sync->push($batch)->assertOk()->json('receivedAt'))->toBe('2026-09-29T11:00:00Z')
        ->and($this->sync->push($batch)->assertOk()->json('receivedAt'))->toBe('2026-09-29T11:00:00Z');

    $key = ['Idempotency-Key' => '01K6AAAAAAAAAAAAAAAAAAAAAB'];
    $tagged = $this->sync->push(TillFixtures::sample('push-request.second-till.json'), $key)->assertOk();
    $this->travelTo('2026-09-29 12:00:00');
    expect($this->sync->push(TillFixtures::sample('push-request.second-till.json'), $key)->assertOk()->json('receivedAt'))
        ->toBe($tagged->json('receivedAt'))->toBe('2026-09-29T11:00:00Z');
});

test('push-request.settings.json replays over HTTP: settings and role permissions are keyed by our own ids', function () {
    $reply = $this->sync->push(TillFixtures::sample('push-request.settings.json'))->assertOk();

    expect(TillFixtures::ack($reply->json()))->toBe(['acknowledgedSeq' => 18263, 'accepted' => 3])
        ->and(SyncApiFixtures::schemaErrors($reply, 'push-reply.schema.json'))->toBe([])
        ->and(DB::table('till_settings')->value('scope_id'))->toBe($this->sync->leeds->id)
        ->and(DB::table('till_settings')->value('id'))->toBe(SyncRowIds::setting('branch', $this->sync->leeds->id, 'receipt.footer_text'))
        ->and(DB::table('till_role_permissions')->count())->toBe(2);
});
