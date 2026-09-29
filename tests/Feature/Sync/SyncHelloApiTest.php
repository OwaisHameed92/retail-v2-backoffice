<?php

use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Enums\IdMapAction;
use App\Domain\Sync\Models\IdMapping;
use App\Domain\Sync\Models\SyncBranchStatus;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/** Module 2.2: `GET /api/v1/sync/hello` (contract v1.3.3 §4.1, hello-reply schema). */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
});

test('hello answers the key\'s company and branch as the till knows them, valid per hello-reply', function () {
    $this->travelTo(now('UTC')->setTime(9, 41, 12));

    $reply = $this->sync->hello()->assertOk()->assertHeader('X-SSPOS-Contract', '1')->assertExactJson([
        'contractVersion' => 1,
        'companyId' => TillFixtures::COMPANY,
        'branchId' => TillFixtures::LEEDS,
        'branchName' => 'Leeds Kirkgate',
        'serverTimeUtc' => now('UTC')->format('Y-m-d\TH:i:s\Z'),
        'maxBatchRows' => 5000,
    ]);
    expect(SyncApiFixtures::schemaErrors($reply, 'hello-reply.schema.json'))->toBe([])
        ->and(array_keys($reply->json()))->toBe(array_keys(TillFixtures::sample('hello-reply.json')));

    $status = SyncBranchStatus::withoutCompanyScope()->where('branch_id', $this->sync->leeds->id)->sole();
    expect($status->last_hello_at?->toIso8601ZuluString())->toBe(now('UTC')->toIso8601ZuluString())
        ->and($status->last_push_at)->toBeNull()
        ->and($status->last_app_version)->toBe('3.0.412')
        ->and($status->last_register_id)->toBe($this->sync->tills[TillFixtures::TILL_1]->id);
});

test('a branch whose till uses an aliased company id gets that alias back; maxBatchRows follows the config', function () {
    $alias = '01K5T0Q8C4000000000000C002';
    IdMapping::withoutCompanyScope()->create([
        'kind' => IdKind::Company, 'till_id' => $alias, 'portal_id' => $this->sync->company->id,
        'company_id' => $this->sync->company->id, 'branch_id' => $this->sync->bradford->id, 'action' => IdMapAction::Aliased,
    ]);
    config(['sync.push.max_rows' => 1000]);

    $this->sync->hello(['X-SSPOS-Company-Id' => $alias], bradford: true)->assertOk()
        ->assertJsonPath('companyId', $alias)
        ->assertJsonPath('branchId', TillFixtures::BRADFORD)
        ->assertJsonPath('branchName', 'Bradford')
        ->assertJsonPath('maxBatchRows', 1000);
});

test('hello errors use the error-reply envelope', function (array $headers, int $status, string $code) {
    config(['licence.api.minimum_app_version' => '3.0.400']);

    $reply = $this->sync->hello($headers)->assertStatus($status)->assertJsonPath('code', $code)->assertHeader('X-SSPOS-Contract', '1');

    expect(SyncApiFixtures::schemaErrors($reply, 'error-reply.schema.json'))->toBe([])
        ->and($reply->headers->get('X-Trace-Id'))->toBe($reply->json('traceId'));
})->with([
    'no key' => [['Authorization' => null], 401, 'auth.invalid_key'],
    'another branch' => [['X-SSPOS-Branch-Id' => TillFixtures::BRADFORD], 403, 'auth.wrong_branch'],
    'another company' => [['X-SSPOS-Company-Id' => '01K5T0Q8C4000000000000C009'], 403, 'auth.wrong_branch'],
    'no contract' => [['X-SSPOS-Contract' => null], 409, 'contract.unsupported'],
    'old till' => [['X-SSPOS-App-Version' => '3.0.100'], 426, 'app.update_required'],
    'no store protocol' => [['X-SSPOS-Store-Protocol' => null], 400, 'request.invalid'],
    'no company header' => [['X-SSPOS-Company-Id' => null], 400, 'request.invalid'],
]);
