<?php

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Sync\Enums\ChangeOutcome;
use Illuminate\Support\Facades\DB;
use Tests\Feature\TillData\TillFixtures;

/*
 * Contract v1.3.1 §19 "synced once, never twice, never backwards": the §19.4 test list, store and applier side.
 * What the portal sends down (pull, 19.4 #3-5 second halves) belongs to modules 2.5/2.6; the bookkeeping it will
 * read (hub_version, hub_hash, origin_branch_id) is asserted here.
 */

beforeEach(function () {
    [$this->company, $this->leeds, $this->bradford] = TillFixtures::tenant();
    $this->product = TillFixtures::sample('entities/Product.json');
    $this->apply = fn (array $changes, $branch = null) => TillFixtures::apply($this->company, $branch ?? $this->leeds, $changes);
    $this->asCompany = fn (Closure $callback) => app(CurrentCompany::class)->runAs($this->company, $callback);
    $this->portalEdit = fn (array $attributes) => ($this->asCompany)(fn () => Product::findOrFail($this->product['id'])->forceFill($attributes)->save());
    // Module 2.5 stamping the pull version of the portal's current row.
    $this->stamp = fn (int $version) => DB::table('products')->update(['hub_version' => $version]);
});

function rulesProduct(array $product, int $seq, int $version, array $changes = [], array $envelope = []): array
{
    return TillFixtures::envelope('Product', [...$product, 'rowVersion' => $version, ...$changes], $seq, ['version' => $version, ...$envelope]);
}

/**
 * What the tables hold, without the columns that say when (not what) was stored.
 *
 * @param  list<string>  $tables
 */
function rulesSnapshot(array $tables): array
{
    $snapshot = [];

    foreach ($tables as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()
            ->map(fn ($row) => array_diff_key((array) $row, array_flip(['synced_at', 'portal_received_at', 'hub_edited_at'])))
            ->all();
    }

    $snapshot['ledger'] = DB::table('sync_applied_changes')->orderBy('seq')->get(['branch_id', 'seq', 'entity', 'entity_id', 'version'])->map(fn ($r) => (array) $r)->all();
    $snapshot['conflicts'] = DB::table('sync_conflicts')->count();

    return $snapshot;
}

it('19.4 #1-2: stores a retried, overlapping batch once, with identical replies and a correct acknowledgedSeq', function () {
    $push = TillFixtures::sample('push-request.json');

    ($this->apply)(array_slice($push, 0, 5));               // stored, but the reply is lost
    $retry = ($this->apply)($push);                         // the till resends from the start, with more rows
    $again = ($this->apply)($push);

    expect(TillFixtures::ack($retry))->toBe(TillFixtures::ack(TillFixtures::sample('push-reply.json')))
        ->and($again->toPushReply())->toBe($retry->toPushReply()) // receivedAt included: the first time, not now
        ->and($retry->count(ChangeOutcome::Duplicate))->toBe(5)
        ->and(DB::table('sales')->count())->toBe(1)
        ->and(DB::table('sale_lines')->count())->toBe(2)
        ->and(DB::table('stock_movements')->count())->toBe(2)
        ->and(DB::table('sync_applied_changes')->count())->toBe(9)
        ->and($retry->receivedAt)->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/');
});

it('19.4 #3: acknowledges a till echoing the portal\'s product without a conflict, a write or a re-send', function () {
    ($this->apply)([rulesProduct($this->product, 1, 1)]);
    ($this->portalEdit)(['sell_price' => '1.50']);
    ($this->stamp)(7);
    $before = (array) DB::table('products')->first();

    // The till applied the pulled row and (wrongly) pushes it back, stamped earlier than the portal edit.
    $echo = ($this->apply)([rulesProduct($this->product, 2, 3, ['sellPrice' => 1.5], ['at' => '2026-09-01T08:00:00Z'])]);

    expect($echo->count(ChangeOutcome::Unchanged))->toBe(1)
        ->and($echo->acknowledgedSeq)->toBe(2)
        ->and(DB::table('sync_conflicts')->count())->toBe(0)
        ->and((array) DB::table('products')->first())->toBe($before)
        ->and($before['hub_version'])->toBe(7)
        ->and($before['origin_branch_id'])->toBeNull()
        ->and($before['hub_hash'])->toHaveLength(64);
});

it('19.4 #4: stores a price changed at shop A once, marks it A\'s for the pull, and takes shop B\'s copy as an echo', function () {
    ($this->apply)([rulesProduct($this->product, 1, 1)]);
    ($this->stamp)(7);

    $edit = rulesProduct($this->product, 2, 2, ['sellPrice' => 1.39]);
    $first = ($this->apply)([$edit]);
    $retry = ($this->apply)([$edit]);

    expect($first->count(ChangeOutcome::Applied))->toBe(1)
        ->and($retry->count(ChangeOutcome::Duplicate))->toBe(1)
        ->and(DB::table('products')->first())
        ->origin_branch_id->toBe(TillFixtures::LEEDS)
        ->hub_version->toBeNull();                          // 2.5 stamps it and sends it to every branch but Leeds

    ($this->stamp)(8);
    $fromBradford = ($this->apply)([rulesProduct($this->product, 1, 5, ['sellPrice' => 1.39])], $this->bradford);

    expect($fromBradford->count(ChangeOutcome::Unchanged))->toBe(1)
        ->and(DB::table('products')->first())
        ->origin_branch_id->toBe(TillFixtures::LEEDS)
        ->hub_version->toBe(8)
        ->and(DB::table('sync_conflicts')->count())->toBe(0);
});

it('19.4 #5: a portal and a till edit of one product before either syncs make one conflict, and the portal wins', function () {
    ($this->apply)([rulesProduct($this->product, 1, 1)]);
    ($this->stamp)(7);
    ($this->portalEdit)(['sell_price' => '1.50']);
    ($this->stamp)(8);

    $tillEdit = rulesProduct($this->product, 2, 2, ['sellPrice' => 1.39], ['baseVersion' => 7, 'at' => now()->addHour()->toIso8601ZuluString()]);
    $result = ($this->apply)([$tillEdit]);
    ($this->apply)([$tillEdit]);

    expect($result->count(ChangeOutcome::Conflict))->toBe(1)
        ->and($result->acknowledgedSeq)->toBe(2)
        ->and(tillRulesPrice())->toBe('1.50')
        ->and(DB::table('sync_conflicts')->count())->toBe(1)
        ->and(DB::table('sync_conflicts')->value('kind'))->toBe('hubVersionNewer');

    // Once the till has the portal's version 8, its next edit applies.
    expect(($this->apply)([rulesProduct($this->product, 3, 3, ['sellPrice' => 1.45], ['baseVersion' => 8])])->count(ChangeOutcome::Applied))->toBe(1)
        ->and(tillRulesPrice())->toBe('1.45');

    // Its own change is not sent back (§19.2), so it still says 8 when the portal stamped it 9: not a conflict.
    ($this->stamp)(9);
    expect(($this->apply)([rulesProduct($this->product, 4, 4, ['sellPrice' => 1.40], ['baseVersion' => 8])])->count(ChangeOutcome::Applied))->toBe(1);
});

it('19.4 #6: keeps the newer row when an older version arrives late; an equal version goes by updatedAt', function () {
    ($this->apply)([rulesProduct($this->product, 1, 5, ['sellPrice' => 1.60, 'updatedAt' => '2026-09-20T10:00:00Z'])]);

    $late = ($this->apply)([rulesProduct($this->product, 2, 4, ['sellPrice' => 1.10])]);
    $sameVersionEarlier = ($this->apply)([rulesProduct($this->product, 1, 5, ['sellPrice' => 1.20, 'updatedAt' => '2026-09-20T09:00:00Z'])], $this->bradford);

    expect($late->count(ChangeOutcome::Stale))->toBe(1)
        ->and($sameVersionEarlier->count(ChangeOutcome::Stale))->toBe(1)
        ->and(tillRulesPrice())->toBe('1.60');

    $sameVersionLater = ($this->apply)([rulesProduct($this->product, 2, 5, ['sellPrice' => 1.70, 'updatedAt' => '2026-09-20T11:00:00Z'])], $this->bradford);

    expect($sameVersionLater->count(ChangeOutcome::Applied))->toBe(1)
        ->and(tillRulesPrice())->toBe('1.70');
});

it('19.4 #7: a product deleted on the portal stays deleted when a late till update arrives', function () {
    ($this->apply)([rulesProduct($this->product, 1, 1)]);
    ($this->stamp)(7);
    ($this->asCompany)(fn () => Product::findOrFail($this->product['id'])->delete());

    $withoutBase = ($this->apply)([rulesProduct($this->product, 2, 2, ['sellPrice' => 1.39], ['at' => '2026-09-23T09:00:00Z'])]);
    ($this->stamp)(8);
    $withBase = ($this->apply)([rulesProduct($this->product, 3, 3, ['sellPrice' => 1.29], ['baseVersion' => 7, 'at' => now()->addHour()->toIso8601ZuluString()])]);

    expect($withoutBase->count(ChangeOutcome::Conflict))->toBe(1)
        ->and($withBase->count(ChangeOutcome::Conflict))->toBe(1)
        ->and(DB::table('products')->value('deleted_at'))->not->toBeNull()
        ->and(tillRulesPrice())->toBe('1.45');

    // A till-owned row: a late, lower version never brings a deleted sale back.
    $sale = collect(TillFixtures::sample('push-request.json'))->firstWhere('entity', 'Sale');
    ($this->apply)([[...$sale, 'seq' => 10, 'op' => 'D', 'version' => 3, 'payload' => [...$sale['payload'], 'rowVersion' => 3, 'deletedAt' => '2026-09-24T00:00:00Z']]]);
    ($this->apply)([[...$sale, 'seq' => 11, 'version' => 2, 'payload' => [...$sale['payload'], 'rowVersion' => 2]]]);

    expect(DB::table('sales')->value('deleted_at'))->toBe('2026-09-24 00:00:00');
});

it('19.4 #8: two tills of one shop selling offline for an hour arrive exactly once, numbered per till', function () {
    $sale = collect(TillFixtures::sample('push-request.json'))->firstWhere('entity', 'Sale');
    $changes = [];

    foreach (range(1, 40) as $n) {
        foreach ([1 => TillFixtures::TILL_1, 2 => TillFixtures::TILL_2] as $till => $registerId) {
            $id = sprintf('01K5VB000000000SR%03d%06d', $till, $n);
            $at = sprintf('2026-09-23T10:%02d:%02dZ', intdiv($n * 90, 60) % 60, ($n * 90) % 60);
            $changes[] = [...$sale, 'seq' => count($changes) + 1, 'entityId' => $id, 'registerId' => $registerId, 'at' => $at, 'key' => "Sale:{$id}:1",
                'payload' => [...$sale['payload'], 'id' => $id, 'registerId' => $registerId, 'number' => $n, 'receiptNumber' => sprintf('LDS-%02d-%06d', $till, $n), 'completedAt' => $at]];
        }
    }

    ($this->apply)(array_slice($changes, 0, 50));
    ($this->apply)(array_slice($changes, 30, 40));      // retry overlapping the first batch
    $last = ($this->apply)(array_slice($changes, 30));

    expect($last->acknowledgedSeq)->toBe(80)
        ->and(DB::table('sales')->count())->toBe(80)
        ->and(DB::table('sales')->where('register_id', TillFixtures::TILL_1)->count())->toBe(40)
        ->and(DB::table('sales')->where('register_id', TillFixtures::TILL_2)->count())->toBe(40)
        ->and(DB::table('sales')->distinct()->count(DB::raw("register_id || '-' || number")))->toBe(80);
});

it('19.4 #9: pushes and pull replays killed a dozen times end with exactly the data of a clean run', function () {
    $push = [...TillFixtures::sample('push-request.json'), ...TillFixtures::sample('push-request.second-till.json')];
    $pull = array_values(array_filter(TillFixtures::sample('pull-reply.json')['changes'], fn ($c) => $c['entity'] !== 'WebOrder'));
    $tables = array_values(array_unique(array_map(fn ($c) => EntityRegistry::get($c['entity'])->table, [...$push, ...$pull])));

    ($this->apply)($pull);
    ($this->apply)($push);
    $clean = rulesSnapshot($tables);

    foreach ([...$tables, 'sync_applied_changes', 'sync_conflicts'] as $table) {
        DB::table($table)->delete();
    }

    mt_srand(19);
    $cursor = 0;

    for ($round = 0; $round < 12; $round++) {
        $unsent = array_values(array_filter($push, fn ($c) => $c['seq'] > $cursor));
        $batch = array_slice($unsent, 0, mt_rand(1, max(1, count($unsent))));
        $pulled = array_slice($pull, 0, mt_rand(1, count($pull)));

        match ($round % 3) {
            0 => DB::transaction(function () use ($batch, $pulled) {   // the connection dies before the commit
                ($this->apply)($pulled);
                ($this->apply)($batch);
                DB::rollBack();
                DB::beginTransaction();
            }),
            1 => [($this->apply)($pulled), ($this->apply)($batch)],   // stored, but the reply is lost
            default => $cursor = [($this->apply)($pulled), ($this->apply)($batch)][1]->acknowledgedSeq,
        };
    }

    while ($cursor < max(array_column($push, 'seq'))) {
        $cursor = ($this->apply)(array_values(array_filter($push, fn ($c) => $c['seq'] > $cursor)))->acknowledgedSeq;
    }
    ($this->apply)($pull);

    expect(rulesSnapshot($tables))->toEqual($clean);
});

it('never stores a user\'s remote approval secret an older till sends: no column, not in extra, not in a conflict', function () {
    $user = ['name' => 'Sam', 'pinHash' => 'pbkdf2$x', 'rfid' => '', 'roleId' => '01K5T0Q8C4000000000000L001', 'role' => null, 'ratePerHour' => 11.44, 'maxShiftHours' => 10, 'isServiceStaff' => false, 'allowCommission' => false, 'isPersonalLicenceHolder' => false, 'simpleModeOverride' => null, 'bigTextMode' => false, 'isActive' => true, 'preferredCulture' => 'en-GB', 'remoteApprovalSecret' => 'JBSWY3DPEHPK3PXP', 'remoteApprovalSecretSetAt' => '2026-09-20T08:00:00Z', 'id' => '01K5T0Q8C4000000000000A009', 'companyId' => TillFixtures::COMPANY, 'createdAt' => '2026-09-01T08:00:00Z', 'updatedAt' => '2026-09-20T08:00:00Z', 'rowVersion' => 1, 'deletedAt' => null, 'isDeleted' => false, 'domainEvents' => []];

    ($this->apply)([TillFixtures::envelope('User', $user, 1)]);
    DB::table('till_users')->update(['hub_edited_at' => now()->addDay()]);
    $conflict = ($this->apply)([TillFixtures::envelope('User', [...$user, 'name' => 'Sam B', 'rowVersion' => 2], 2, ['version' => 2])]);
    $row = (array) DB::table('till_users')->first();

    expect($conflict->count(ChangeOutcome::Conflict))->toBe(1)
        ->and(json_encode($row))->not->toContain('JBSWY3DPEHPK3PXP')
        ->and(array_keys($row))->not->toContain('remote_approval_secret')
        // v1.4 removed both members; the v1.3.1 column stays (additive migrations) and is never written again.
        ->and($row['remote_approval_secret_set_at'])->toBeNull()
        ->and($row['extra'])->toBeNull()
        ->and(json_decode((string) DB::table('sync_conflicts')->value('incoming_payload'), true))->not->toHaveKey('remoteApprovalSecret')->toHaveKey('name')
        ->and((string) DB::table('sync_conflicts')->value('incoming_payload'))->not->toContain('JBSWY3DPEHPK3PXP');
});

it('keeps the time a row first reached the portal, and the latest in synced_at', function () {
    $this->travelTo('2026-09-23 10:00:00');
    ($this->apply)([rulesProduct($this->product, 1, 1)]);
    $this->travelTo('2026-09-23 11:00:00');
    ($this->apply)([rulesProduct($this->product, 2, 2, ['sellPrice' => 1.39])]);

    expect(DB::table('products')->first())
        ->portal_received_at->toBe('2026-09-23 10:00:00')
        ->synced_at->toBe('2026-09-23 11:00:00');
});

function tillRulesPrice(): string
{
    return number_format((float) DB::table('products')->value('sell_price'), 2, '.', '');
}
