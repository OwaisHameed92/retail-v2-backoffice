<?php

use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Sync\BranchDepartures;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/*
 * Contract v1.4.1 answers of 2026-09-29 (b), section A: a blank `User.rfid` / `pinHash` is never sent (A.1), and a
 * shop's own hub row moved to another shop reaches the old shop as a `D` (A.3); PromotionRule.isGroupOffer.
 */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->travelTo('2026-09-29 10:00:00');
    $this->valid = fn ($reply) => expect(SyncApiFixtures::schemaErrors($reply, 'pull-reply.schema.json'))->toBe([]);
    $this->title = '01K5T0Q8C40000000000NT0001';
    $this->newsTitle = fn (?string $branchId) => Pull::portalCreate($this->company, 'NewsTitle', Pull::payload('NewsTitle', $this->title), ['branch_id' => $branchId]);
    $this->move = function (?string $branchId): void {
        $this->travel(1)->minutes();
        Pull::portalUpdate($this->company, 'NewsTitle', $this->title, ['branch_id' => $branchId]);
    };
    $this->forTitle = fn ($reply) => array_values(array_filter(Pull::changes($reply), fn (array $c) => $c['entityId'] === $this->title));
});

test('A.1: a blank rfid or pinHash is never sent (the till keeps its fob and PIN); a real one is', function () {
    Pull::portalCreate($this->company, 'User', Pull::payload('User', '01K5T0Q8C40000000000SR1001', ['pinHash' => '', 'rfid' => '']));
    Pull::portalCreate($this->company, 'User', Pull::payload('User', '01K5T0Q8C40000000000SR1002', ['pinHash' => 'pbkdf2$100000$AAECAwQFBgcICQoLDA0ODw==$hp5sg1DFvrCsw5n7qsO2DSIEM4lrJqZHc00NjxWG4fo=', 'rfid' => '0004512345']));
    Pull::portalCreate($this->company, 'User', Pull::payload('User', '01K5T0Q8C40000000000SR1003', ['pinHash' => '', 'rfid' => '']));
    DB::table('till_users')->where('id', '01K5T0Q8C40000000000SR1003')->update(['pin_hash' => null, 'rfid' => null]);

    $reply = $this->sync->pull(0)->assertOk();
    ($this->valid)($reply);
    $payloads = collect(Pull::changes($reply))->pluck('payload', 'entityId');

    expect($payloads['01K5T0Q8C40000000000SR1001'])->not->toHaveKeys(['pinHash', 'rfid'])
        ->and($payloads['01K5T0Q8C40000000000SR1003'])->not->toHaveKeys(['pinHash', 'rfid'])
        ->and($payloads['01K5T0Q8C40000000000SR1002'])->toMatchArray(['pinHash' => 'pbkdf2$100000$AAECAwQFBgcICQoLDA0ODw==$hp5sg1DFvrCsw5n7qsO2DSIEM4lrJqZHc00NjxWG4fo=', 'rfid' => '0004512345'])
        ->and((string) $reply->getContent())->not->toContain('"rfid":null', '"rfid":""', '"pinHash":""', '"pinHash":null');
});

test('PromotionRule.isGroupOffer: stored as the till sends it, and pulled as the rule now reads', function () {
    $rule = fn (string $id, array $overrides) => Pull::payload('PromotionRule', $id, $overrides);
    $this->sync->push([TillFixtures::envelope('PromotionRule', $rule('01K5T0Q8C40000000000PR0001', ['type' => 'percentOff', 'scope' => 'product', 'minQuantity' => 3, 'isGroupOffer' => true]), 1)])->assertOk();
    Pull::portalCreate($this->company, 'PromotionRule', $rule('01K5T0Q8C40000000000PR0002', ['type' => 'fixedPrice', 'scope' => 'basket', 'minQuantity' => 2]));
    Pull::portalCreate($this->company, 'PromotionRule', $rule('01K5T0Q8C40000000000PR0003', ['type' => 'fixedOff', 'scope' => 'category', 'minQuantity' => 2]));

    $bradford = collect(Pull::changes($this->sync->pull(0, bradford: true)->assertOk()))->pluck('payload.isGroupOffer', 'entityId')->sortKeys();

    expect((bool) DB::table('promotion_rules')->where('id', '01K5T0Q8C40000000000PR0001')->value('is_group_offer'))->toBeTrue()
        ->and($bradford->all())->toBe(['01K5T0Q8C40000000000PR0001' => true, '01K5T0Q8C40000000000PR0002' => false, '01K5T0Q8C40000000000PR0003' => true]);
});

test('A.3: a shop row moved to another shop is a D for the old shop (its branchId, no payload) and the row for the new one', function () {
    ($this->newsTitle)($this->sync->leeds->id);
    $leedsBefore = $this->sync->pull(0)->assertOk();
    expect(($this->forTitle)($leedsBefore))->toHaveCount(1);

    ($this->move)($this->sync->bradford->id);

    $leeds = $this->sync->pull($leedsBefore->json('highestVersion'))->assertOk();
    $bradford = $this->sync->pull(0, bradford: true)->assertOk();
    ($this->valid)($leeds);
    ($this->valid)($bradford);
    [$delete] = ($this->forTitle)($leeds);

    expect(Pull::changes($leeds))->toHaveCount(1)
        ->and($delete)->toMatchArray(['entity' => 'NewsTitle', 'op' => 'D', 'branchId' => TillFixtures::LEEDS, 'companyId' => TillFixtures::COMPANY, 'payload' => null, 'registerId' => ''])
        ->and($delete['version'])->toBeGreaterThan($leedsBefore->json('highestVersion'))
        ->and($delete['key'])->toBe("NewsTitle:{$this->title}:{$delete['version']}")
        ->and($delete['at'])->toBe('2026-09-29T10:01:00Z')
        ->and(($this->forTitle)($bradford)[0])->toMatchArray(['op' => 'U', 'branchId' => TillFixtures::BRADFORD])
        ->and(($this->forTitle)($bradford)[0]['payload']['branchId'])->toBe(TillFixtures::BRADFORD)
        // Pulled again from the same point: the same D (never lost, never doubled).
        ->and(Pull::summary($this->sync->pull($leedsBefore->json('highestVersion'))))->toBe(Pull::summary($leeds));
});

test('A.3: a shop offline through two moves still gets its D; a row that comes back is sent again, not deleted', function () {
    $york = Branch::factory()->forCompany($this->company)->create(['code' => 'YRK']);
    ($this->newsTitle)($this->sync->leeds->id);
    $since = (int) $this->sync->pull(0)->json('highestVersion');

    ($this->move)($this->sync->bradford->id);
    ($this->move)($york->id);

    expect(Pull::summary($this->sync->pull($since)))->toHaveCount(1)
        ->and(($this->forTitle)($this->sync->pull($since))[0]['op'])->toBe('D')
        ->and(($this->forTitle)($this->sync->pull(0, bradford: true))[0]['op'])->toBe('D');

    // Back to Leeds: Leeds gets the row itself (no D); York, which it left, gets a D.
    ($this->move)($this->sync->leeds->id);
    $leeds = ($this->forTitle)($this->sync->pull($since));

    expect($leeds)->toHaveCount(1)
        ->and($leeds[0]['op'])->toBe('U')
        ->and($leeds[0]['payload']['branchId'])->toBe(TillFixtures::LEEDS)
        ->and(DB::table(BranchDepartures::TABLE)->where('entity_id', $this->title)->pluck('branch_id')->sort()->values()->all())
        ->toBe(collect([$this->sync->bradford->id, $york->id])->sort()->values()->all());
});

test('A.3 / 2026-09-30 point 2: every shop\'s row made one shop\'s: each other shop gets a D with its own branchId, no payload', function () {
    ($this->newsTitle)(null);
    $since = (int) $this->sync->pull(0, bradford: true)->json('highestVersion');

    ($this->move)($this->sync->leeds->id);
    $reply = $this->sync->pull($since, bradford: true)->assertOk();
    ($this->valid)($reply);
    $bradford = ($this->forTitle)($reply);
    $leeds = ($this->forTitle)($this->sync->pull(0));

    expect($bradford)->toHaveCount(1)
        ->and($bradford[0])->toMatchArray(['op' => 'D', 'branchId' => TillFixtures::BRADFORD, 'payload' => null])
        ->and($leeds)->toHaveCount(1)
        ->and($leeds[0])->toMatchArray(['op' => 'U', 'branchId' => TillFixtures::LEEDS])
        ->and($leeds[0]['payload']['branchId'])->toBe(TillFixtures::LEEDS);

    // Every shop's again: no D left for anyone, Bradford gets the row back.
    ($this->move)(null);

    expect(DB::table(BranchDepartures::TABLE)->count())->toBe(0)
        ->and(($this->forTitle)($this->sync->pull($since, bradford: true))[0]['op'])->toBe('U');
});

test('A.3: a till that moves the row itself gets no D; the other shops do', function () {
    ($this->newsTitle)(null);
    $this->travel(1)->minutes();
    $pushed = Pull::payload('NewsTitle', $this->title, ['branchId' => TillFixtures::LEEDS, 'updatedAt' => '2026-09-29T10:01:00Z', 'rowVersion' => 2]);
    $this->sync->push([TillFixtures::envelope('NewsTitle', $pushed, 7, ['version' => 2, 'branchId' => TillFixtures::LEEDS])])->assertOk();

    expect(DB::table(BranchDepartures::TABLE)->pluck('branch_id')->all())->toBe([$this->sync->bradford->id])
        ->and(($this->forTitle)($this->sync->pull(0, bradford: true))[0]['op'])->toBe('D')
        ->and(($this->forTitle)($this->sync->pull(0)))->toBe([]);   // Leeds pushed it: never echoed, no D
});

test('A.3: departures stay inside their business', function () {
    ($this->newsTitle)($this->sync->leeds->id);
    ($this->move)($this->sync->bradford->id);
    $other = new SyncApiFixtures($this, mapTillIds: false);

    $otherPull = $this->call('GET', '/api/v1/sync/pull?since=0', [], [], [], collect([
        ...$other->headers(), 'X-SSPOS-Company-Id' => $other->company->id, 'X-SSPOS-Branch-Id' => $other->leeds->id,
    ])->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all())->assertOk();

    expect($otherPull->json('changes'))->toBe([])
        ->and(DB::table(BranchDepartures::TABLE)->where('company_id', $other->company->id)->count())->toBe(0);
});
