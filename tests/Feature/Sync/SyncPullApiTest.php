<?php

use App\Domain\Sync\Actions\IssueSyncKey;
use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Enums\IdMapAction;
use App\Domain\Sync\Enums\SyncKeySource;
use App\Domain\Sync\Models\IdMapping;
use App\Domain\Sync\Models\SyncBranchStatus;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Sync\HubVersions;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/** Module 2.5: `GET /api/v1/sync/pull` (contract v1.4.1 §5, §6, §8, §10, §11, §19). */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->travelTo('2026-09-29 10:00:00');
    $this->valid = fn ($reply) => expect(SyncApiFixtures::schemaErrors($reply, 'pull-reply.schema.json'))->toBe([]);
});

/** The pull-reply sample's rows (no WebOrder, §12 is a proposal) as a Leeds push, in reverse order. */
function pullSampleAsPush(): array
{
    $rows = array_values(array_filter(TillFixtures::sample('pull-reply.json')['changes'], fn ($c) => $c['entity'] !== 'WebOrder'));
    $push = [];

    foreach (array_reverse($rows) as $n => $row) {
        $push[] = TillFixtures::envelope($row['entity'], $row['payload'], $n + 1, ['version' => 1]);
    }

    return $push;
}

test('portal creates, updates and deletes a category, product and barcode: pulled in order with ops, versions and the till\'s shape', function () {
    Pull::portalCreate($this->company, 'Category', TillFixtures::sample('entities/Category.json'));
    $this->travel(1)->minutes();
    Pull::portalCreate($this->company, 'Product', TillFixtures::sample('entities/Product.json'));
    $this->travel(1)->minutes();
    Pull::portalCreate($this->company, 'ProductBarcode', TillFixtures::sample('entities/ProductBarcode.json'));

    $first = $this->sync->pull(0)->assertOk()->assertHeader('X-SSPOS-Contract', '1');
    ($this->valid)($first);
    expect(Pull::summary($first))->toBe([['Category', 'I', 1], ['Product', 'I', 2], ['ProductBarcode', 'I', 3]])
        ->and($first->json('highestVersion'))->toBe(3)
        ->and($first->json('hasMore'))->toBeFalse();

    $this->travel(1)->minutes();
    Pull::portalUpdate($this->company, 'Product', '01K5T0Q8C4000000000000P001', ['sell_price' => '1.50']);
    $this->travel(1)->minutes();
    Pull::portalDelete($this->company, 'ProductBarcode', '01K5T0Q8C40000000000PB0001');

    $next = $this->sync->pull(3)->assertOk();
    ($this->valid)($next);
    expect(Pull::summary($next))->toBe([['Product', 'U', 4], ['ProductBarcode', 'D', 5]])
        ->and($next->json('highestVersion'))->toBe(5);

    $all = $this->sync->pull(0)->assertOk();
    expect(Pull::summary($all))->toBe([['Category', 'I', 1], ['Product', 'U', 4], ['ProductBarcode', 'D', 5]]);

    [$category, $product, $barcode] = Pull::changes($all);
    expect($product)->toMatchArray([
        'seq' => 0, 'entityId' => '01K5T0Q8C4000000000000P001', 'companyId' => TillFixtures::COMPANY, 'branchId' => '',
        'registerId' => '', 'at' => '2026-09-29T10:03:00Z', 'key' => 'Product:01K5T0Q8C4000000000000P001:4',
    ])->and($product['payload'])->toMatchArray([
        'id' => '01K5T0Q8C4000000000000P001', 'companyId' => TillFixtures::COMPANY, 'sellPrice' => 1.5, 'costPrice' => 0.98,
        'minStockQty' => 6, 'unitType' => 'pcs', 'ageRule' => 'none', 'description' => null, 'trackStock' => true,
        'createdAt' => '2026-09-29T10:01:00Z', 'updatedAt' => '2026-09-29T10:03:00Z', 'rowVersion' => 1,
        'deletedAt' => null, 'isDeleted' => false, 'domainEvents' => [],
    ])->and($product['payload'])->not->toHaveKeys(['priceIncVat', 'barcodes', 'hub_version', 'extra'])
        ->and(array_keys($category['payload']))->toContain('parentCategoryId', 'negativeStockMode')
        ->and($barcode['payload'])->toMatchArray(['deletedAt' => '2026-09-29T10:04:00Z', 'isDeleted' => true, 'barcode' => '0400001042175']);

    $empty = $this->sync->pull(5)->assertOk();
    ($this->valid)($empty);
    expect($empty->json())->toBe(['changes' => [], 'highestVersion' => 5, 'hasMore' => false])
        ->and(array_keys($empty->json()))->toBe(array_keys(TillFixtures::sample('pull-reply.empty.json')));
});

test('pages with max and hasMore, and since filters what was already applied', function () {
    foreach (range(1, 5) as $n) {
        $this->travel(1)->seconds();
        Pull::portalCreate($this->company, 'Product', [...TillFixtures::sample('entities/Product.json'), 'id' => sprintf('01K5T0Q8C4000000000000P%03d', $n + 10)]);
    }

    $pages = [];
    $since = 0;

    do {
        $reply = $this->sync->pull($since, 2)->assertOk();
        ($this->valid)($reply);
        $pages[] = [array_column(Pull::changes($reply), 'version'), $reply->json('hasMore')];
        $since = $reply->json('highestVersion');
    } while ($reply->json('hasMore'));

    expect($pages)->toBe([[[1, 2], true], [[3, 4], true], [[5], false]])
        ->and(array_column(Pull::changes($this->sync->pull(3)), 'version'))->toBe([4, 5])
        ->and(Pull::changes($this->sync->pull(0, 9000)))->toHaveCount(5);   // max above 5,000 is capped, not refused
});

test('rows addressed to a branch go to that branch only; company-wide rows go to every branch', function () {
    Pull::portalCreate($this->company, 'Product', TillFixtures::sample('entities/Product.json'));
    Pull::portalCreate($this->company, 'NewsTitle', Pull::payload('NewsTitle', '01K5T0Q8C40000000000NT0001'), ['branch_id' => $this->sync->leeds->id]);
    Pull::portalCreate($this->company, 'NewsTitle', Pull::payload('NewsTitle', '01K5T0Q8C40000000000NT0002'), ['branch_id' => $this->sync->bradford->id]);

    $leeds = $this->sync->pull(0)->assertOk();
    $bradford = $this->sync->pull(0, bradford: true)->assertOk();
    ($this->valid)($leeds);

    expect(array_column(Pull::changes($leeds), 'entityId'))->toBe(['01K5T0Q8C4000000000000P001', '01K5T0Q8C40000000000NT0001'])
        ->and(array_column(Pull::changes($bradford), 'entityId'))->toBe(['01K5T0Q8C4000000000000P001', '01K5T0Q8C40000000000NT0002'])
        ->and(Pull::changes($leeds)[0]['branchId'])->toBe('')
        ->and(Pull::changes($leeds)[1]['branchId'])->toBe(TillFixtures::LEEDS)                 // the till's own branch id
        ->and(Pull::changes($leeds)[1]['payload']['branchId'])->toBe(TillFixtures::LEEDS)
        ->and(Pull::changes($bradford)[1]['branchId'])->toBe(TillFixtures::BRADFORD);
});

test('till-owned entities are never sent, and only hub-owned tables are read', function () {
    $this->sync->push(TillFixtures::sample('push-request.json'))->assertOk();
    $this->sync->push(TillFixtures::sample('push-request.second-branch.json'), bradford: true)->assertOk();

    expect(Pull::changes($this->sync->pull(0)))->toBe([])
        ->and(Pull::changes($this->sync->pull(0, bradford: true)))->toBe([]);

    $ownership = TillFixtures::sample('ownership.json')['entities'];
    foreach (HubVersions::entities() as $def) {
        expect($ownership[$def->entity])->toBe('hub');
    }
    expect(collect(HubVersions::entities())->pluck('entity'))->not->toContain('Sale', 'Company', 'Branch', 'Register', 'StockMovement');
});

test('never echoed: a row Leeds pushed goes to Bradford (parents first, in the till\'s shape) but not back to Leeds', function () {
    $this->sync->push(pullSampleAsPush())->assertOk();

    expect(Pull::changes($this->sync->pull(0)))->toBe([]);

    $bradford = $this->sync->pull(0, bradford: true)->assertOk();
    ($this->valid)($bradford);
    $sample = array_values(array_filter(TillFixtures::sample('pull-reply.json')['changes'], fn ($c) => $c['entity'] !== 'WebOrder'));

    expect(array_column(Pull::changes($bradford), 'entityId'))->toBe(array_column($sample, 'entityId'))
        ->and(array_column(Pull::changes($bradford), 'version'))->toBe(range(1, 9));

    foreach (Pull::changes($bradford) as $i => $change) {
        $derived = array_diff(EntityRegistry::get($change['entity'])->derived, ['isDeleted', 'domainEvents']);
        // §10.1: a customer's balance and points are the portal's sum of its ledger (none here), never the till's cache.
        // owed / creditHeld (till 0.1.51) are worked out from that balance, never stored.
        $ledger = $change['entity'] === 'Customer' ? ['balance' => 0, 'points' => 0, 'owed' => 0, 'creditHeld' => 0] : [];
        expect($change['payload'])->toEqual([...array_diff_key($sample[$i]['payload'], array_flip($derived)), ...$ledger])
            ->and($change['op'])->toBe('I');
    }

    // Bradford echoes one back unchanged: nothing new for anyone. Then the portal edits it: both branches get it.
    $echo = collect(pullSampleAsPush())->firstWhere('entityId', '01K5T0Q8C4000000000000P001');
    $this->sync->push([[...$echo, 'seq' => 1]], bradford: true)->assertOk();
    expect(Pull::changes($this->sync->pull(9, bradford: true)))->toBe([])
        ->and(Pull::changes($this->sync->pull(9)))->toBe([]);

    $this->travel(1)->minutes();
    Pull::portalUpdate($this->company, 'Product', '01K5T0Q8C4000000000000P001', ['sell_price' => '1.49']);
    expect(Pull::summary($this->sync->pull(9)))->toBe([['Product', 'U', 10]])
        ->and(Pull::summary($this->sync->pull(9, bradford: true)))->toBe([['Product', 'U', 10]]);

    // Bradford changes the price: Leeds gets Bradford's version, Bradford does not get its own back.
    $later = ['sellPrice' => 1.29, 'rowVersion' => 2, 'updatedAt' => '2026-09-29T10:05:00Z'];
    $this->sync->push([TillFixtures::envelope('Product', [...$echo['payload'], ...$later], 2, ['version' => 2])], bradford: true)->assertOk();
    $leeds = $this->sync->pull(10)->assertOk();
    expect(Pull::summary($leeds))->toBe([['Product', 'U', 11]])
        ->and(Pull::changes($leeds)[0]['payload']['sellPrice'])->toBe(1.29)
        ->and(Pull::changes($this->sync->pull(10, bradford: true)))->toBe([]);
});

test('ids go out as the till knows them: the company alias the branch uses, its own branch id', function () {
    IdMapping::withoutCompanyScope()->create([
        'kind' => IdKind::Company, 'till_id' => '01K5T0Q8C4000000000000C002', 'portal_id' => $this->company->id,
        'company_id' => $this->company->id, 'branch_id' => $this->sync->bradford->id, 'action' => IdMapAction::Aliased,
    ]);
    Pull::portalCreate($this->company, 'NewsTitle', Pull::payload('NewsTitle', '01K5T0Q8C40000000000NT0002'), ['branch_id' => $this->sync->bradford->id]);
    Pull::portalCreate($this->company, 'Product', TillFixtures::sample('entities/Product.json'));

    $bradford = Pull::changes($this->sync->pull(0, bradford: true)->assertOk());
    $leeds = Pull::changes($this->sync->pull(0)->assertOk());

    expect(array_unique(array_column($bradford, 'companyId')))->toBe(['01K5T0Q8C4000000000000C002'])
        ->and(array_unique(array_column(array_column($bradford, 'payload'), 'companyId')))->toBe(['01K5T0Q8C4000000000000C002'])
        ->and($bradford[0]['branchId'])->toBe(TillFixtures::BRADFORD)
        ->and($bradford[0]['payload']['branchId'])->toBe(TillFixtures::BRADFORD)
        ->and($leeds[0]['companyId'])->toBe(TillFixtures::COMPANY)
        ->and($leeds[0]['payload']['companyId'])->toBe(TillFixtures::COMPANY)
        ->and(json_encode([$bradford, $leeds]))->not->toContain($this->company->id, $this->sync->bradford->id);
});

test('secrets never go down: no remote approval secret, no secret-looking extra member', function () {
    $user = Pull::payload('User', '01K5T0Q8C40000000000SS0001', [
        // An older till still sends the members v1.4 removed (§10.7).
        'remoteApprovalSecret' => 'TOP-SECRET-1234', 'remoteApprovalSecretSetAt' => '2026-09-20T08:00:00Z',
        'apiKey' => 'SSK-LEAKED-KEY', 'favouriteColour' => 'green',
    ]);
    $this->sync->push([TillFixtures::envelope('User', $user, 1)])->assertOk();

    $reply = $this->sync->pull(0, bradford: true)->assertOk();
    ($this->valid)($reply);
    $payload = Pull::changes($reply)[0]['payload'];

    expect(Pull::changes($reply)[0]['entity'])->toBe('User')
        ->and($payload)->not->toHaveKeys(['remoteApprovalSecret', 'remoteApprovalSecretSetAt', 'apiKey'])
        ->and($payload['favouriteColour'])->toBe('green')                    // a newer till's member goes back as it came
        ->and((string) $reply->getContent())->not->toContain('TOP-SECRET', 'SSK-LEAKED', 'redacted');
});

test('records the pull in sync_branch_status and gzips the reply when the till accepts it', function () {
    Pull::portalCreate($this->company, 'Product', TillFixtures::sample('entities/Product.json'));

    $reply = $this->sync->pull(0, headers: ['Accept-Encoding' => 'gzip, deflate'])->assertOk()->assertHeader('Content-Encoding', 'gzip');
    $status = SyncBranchStatus::withoutCompanyScope()->where('branch_id', $this->sync->leeds->id)->firstOrFail();

    expect(json_decode((string) gzdecode((string) $reply->getContent()), true)['highestVersion'])->toBe(1)
        ->and($status->last_pull_at?->toIso8601ZuluString())->toBe('2026-09-29T10:00:00Z')
        ->and([$status->last_pull_since, $status->last_pull_version, $status->last_pull_rows])->toBe([0, 1, 1])
        ->and($status->last_app_version)->toBe('3.0.412');
});

test('the same auth, contract, header and error envelope as push; since and max are checked', function () {
    $s = $this->sync;
    $errors = [
        $s->pull(0, headers: ['Authorization' => null])->assertStatus(401)->assertJsonPath('code', 'auth.invalid_key'),
        $s->pull(0, headers: ['X-SSPOS-Contract' => '2'])->assertStatus(409)->assertJsonPath('code', 'contract.unsupported'),
        $s->pull(0, headers: ['X-SSPOS-Branch-Id' => TillFixtures::BRADFORD])->assertStatus(403)->assertJsonPath('code', 'auth.wrong_branch'),
        $s->pull(0, headers: ['X-SSPOS-App-Version' => null])->assertStatus(400)->assertJsonPath('code', 'request.invalid'),
        $s->pull(null)->assertStatus(400)->assertJsonPath('code', 'request.invalid'),
        $s->pull('-1')->assertStatus(400),
        $s->pull('abc')->assertStatus(400),
        $s->pull(0, 0)->assertStatus(400),
        $s->pull(0, 'ten')->assertStatus(400),
    ];

    foreach ($errors as $error) {
        expect(SyncApiFixtures::schemaErrors($error, 'error-reply.schema.json'))->toBe([]);
    }

    expect(SyncBranchStatus::withoutCompanyScope()->where('branch_id', $s->leeds->id)->value('last_error_code'))->toBe('request.invalid');
});

test('tenant isolation: another business\'s rows and counter never reach this company\'s tills', function () {
    $other = Company::factory()->create();
    $otherBranch = Branch::factory()->forCompany($other)->create(['code' => 'OTH']);
    $otherKey = app(IssueSyncKey::class)->handle($otherBranch, SyncKeySource::Admin);

    Pull::portalCreate($other, 'Product', [...TillFixtures::sample('entities/Product.json'), 'id' => '01K5T0Q8C4000000000000P900']);
    Pull::portalCreate($this->company, 'Product', TillFixtures::sample('entities/Product.json'));

    $ours = Pull::changes($this->sync->pull(0)->assertOk());
    $theirs = Pull::changes($this->sync->pull(0, headers: [
        'Authorization' => "Bearer {$otherKey}", 'X-SSPOS-Company-Id' => $other->id, 'X-SSPOS-Branch-Id' => $otherBranch->id,
    ])->assertOk());

    expect(array_column($ours, 'entityId'))->toBe(['01K5T0Q8C4000000000000P001'])
        ->and(array_column($theirs, 'entityId'))->toBe(['01K5T0Q8C4000000000000P900'])
        ->and(array_column($theirs, 'version'))->toBe([1])                   // one counter per company
        ->and($theirs[0]['companyId'])->toBe($other->id);

    // Company B's key with company A's till ids is refused before any row is read.
    $this->sync->pull(0, headers: ['Authorization' => "Bearer {$otherKey}"])->assertStatus(403);
});
