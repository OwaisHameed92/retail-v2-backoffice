<?php

use App\Domain\Shared\Support\Ulid;
use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Enums\IdMapAction;
use App\Domain\Sync\Models\IdMapping;
use App\Domain\TillData\Models\VatRate;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;
use Tests\Support\ContractSchema;

/*
 * Module 2.6: sync samples not replayed elsewhere (contract v1.4.1 §7, §9, §17.8). The other sync samples are
 * replayed by the tests named in tests/Support/ContractSampleCoverage.php.
 */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
});

/** Our reply has the sample's members in the sample's order, and the given values. */
function sameShapeAs(array $reply, string $sample): void
{
    expect(array_keys($reply))->toBe(array_keys(TillFixtures::sample($sample)));
}

test('error-reply.401.json: an unknown sync key gets the same envelope and code', function () {
    $sample = TillFixtures::sample('error-reply.401.json');
    $reply = $this->sync->hello(['Authorization' => 'Bearer SSK-0000-0000-0000-0000-0000-0000-0000-0000'])->assertStatus(401);

    sameShapeAs($reply->json(), 'error-reply.401.json');
    expect($reply->json('code'))->toBe($sample['code'])
        ->and($reply->json('retryAfterSeconds'))->toBe($sample['retryAfterSeconds'])
        ->and($reply->json('rejectedKey'))->toBe($sample['rejectedKey'])
        ->and($reply->json('traceId'))->toBe($reply->headers->get('X-Trace-Id'))
        ->and(ContractSchema::errors($reply->json(), 'schemas/error-reply.schema.json'))->toBe([]);
});

test('error-reply.422.json: a sale total with 3 decimal places as the first row is 422 row.invalid naming that row', function () {
    $sample = TillFixtures::sample('error-reply.422.json');
    $push = TillFixtures::sample('push-request.json');
    $sale = array_search('Sale', array_column($push, 'entity'), true);
    $push[$sale]['payload']['total'] = 5.155;
    $batch = [$push[$sale], ...array_slice($push, 0, $sale), ...array_slice($push, $sale + 1)];
    $batch = array_map(fn (array $change, int $i) => [...$change, 'seq' => $i + 1], $batch, array_keys($batch));

    $reply = $this->sync->push($batch)->assertStatus(422);

    sameShapeAs($reply->json(), 'error-reply.422.json');
    expect($reply->json('code'))->toBe($sample['code'])
        ->and($reply->json('rejectedKey'))->toBe($sample['rejectedKey'])
        ->and($reply->json('message'))->toContain('Sale 01K5VB000000000SR001000482')
        ->and($reply->json('retryAfterSeconds'))->toBeNull()
        ->and(DB::table('sales')->count())->toBe(0);
});

test('push-request.initial.json: a moving shop\'s history upload (initial mode, its own company id) is stored once', function () {
    $sample = TillFixtures::sample('../licensing/samples/push-request.initial.json');
    $tillCompany = $sample[0]['companyId'];
    // cloud/migrate (module 2.8) will record the moving shop's own company id; here it is mapped by hand.
    IdMapping::withoutCompanyScope()->create([
        'kind' => IdKind::Company, 'till_id' => $tillCompany, 'portal_id' => $this->sync->company->id,
        'company_id' => $this->sync->company->id, 'branch_id' => $this->sync->leeds->id, 'action' => IdMapAction::Aliased,
    ]);
    $headers = ['X-SSPOS-Company-Id' => $tillCompany, 'X-SSPOS-Sync-Mode' => 'initial', 'X-SSPOS-Upload-Id' => Ulid::new(), 'Idempotency-Key' => Ulid::new()];

    $first = $this->sync->push($sample, $headers)->assertOk();
    $retry = $this->sync->push($sample, $headers)->assertOk()->assertHeader('Idempotency-Replayed', 'true');

    expect(TillFixtures::ack($first->json()))->toBe(['acknowledgedSeq' => 2, 'accepted' => 2])
        ->and($retry->json())->toBe($first->json())
        ->and(VatRate::withoutCompanyScope()->orderBy('code')->pluck('percentage', 'code')->all())->toBe(['S' => '20.0000', 'Z' => '0.0000'])
        ->and(VatRate::withoutCompanyScope()->pluck('company_id')->unique()->all())->toBe([$this->sync->company->id])
        // The upload's own seqs 1…N are kept apart from the branch's ChangeLog seqs.
        ->and(DB::table('sync_applied_changes')->where('seq', 1)->value('stream'))->toBe($headers['X-SSPOS-Upload-Id']);
});
