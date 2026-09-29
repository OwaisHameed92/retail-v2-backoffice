<?php

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\CustomerOrder;
use App\Domain\TillData\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 2.6: contract v1.4.1 §19.4 test list end to end over HTTP (push and pull through the real endpoints, every
 * reply checked against the schemas by ContractReplyGuard). docs/contract-tests.md maps each item to its tests;
 * the store-level cases are in tests/Feature/TillData/SyncRulesTest.php.
 */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->travelTo('2026-09-29 10:00:00');
    $this->product = TillFixtures::sample('entities/Product.json');
    $this->asCompany = fn (Closure $callback) => app(CurrentCompany::class)->runAs($this->company, $callback);
});

/** A Product envelope at a row version, as a till pushes it. */
function listProduct(array $product, int $seq, int $version, array $changes = [], array $envelope = []): array
{
    return TillFixtures::envelope('Product', [...$product, 'rowVersion' => $version, ...$changes], $seq, ['version' => $version, ...$envelope]);
}

test('19.4 #1-2 over HTTP: a batch pushed twice, and a retry overlapping it, store one set of rows with identical replies', function () {
    $batch = TillFixtures::sample('push-request.json');

    $lost = $this->sync->push(array_slice($batch, 0, 4))->assertOk();          // stored; the till never saw the reply
    $retry = $this->sync->push($batch)->assertOk();                           // resent from the start, with more rows
    $again = $this->sync->push($batch)->assertOk();

    expect($lost->json('acknowledgedSeq'))->toBe(18234)
        ->and(TillFixtures::ack($retry->json()))->toBe(TillFixtures::ack(TillFixtures::sample('push-reply.json')))
        ->and($again->json())->toBe($retry->json())
        ->and(DB::table('sales')->count())->toBe(1)
        ->and(DB::table('sale_lines')->count())->toBe(2)
        ->and(DB::table('customer_orders')->count())->toBe(1)
        ->and(DB::table('sync_applied_changes')->count())->toBe(9);
});

test('19.4 #3 over HTTP: a product made on the portal reaches Leeds once; Leeds sending it back changes nothing and goes nowhere', function () {
    Pull::portalCreate($this->company, 'Product', $this->product);

    $pulled = $this->sync->pull(0)->assertOk();
    expect(Pull::summary($pulled))->toBe([['Product', 'I', 1]]);

    // A till must not push a pulled row (§19.2); if one did, it is acknowledged as already had.
    $echo = Pull::changes($pulled)[0];
    $reply = $this->sync->push([[...$echo, 'seq' => 1, 'version' => 1, 'payload' => [...$echo['payload'], 'rowVersion' => 1]]])->assertOk();

    expect(TillFixtures::ack($reply->json()))->toBe(['acknowledgedSeq' => 1, 'accepted' => 1])
        ->and(Pull::changes($this->sync->pull(1)))->toBe([])
        ->and(Pull::summary($this->sync->pull(0, bradford: true)))->toBe([['Product', 'I', 1]])
        ->and(DB::table('sync_conflicts')->count())->toBe(0);
});

test('19.4 #6-7 over HTTP: a late, older version never wins, and a product deleted on the portal stays deleted', function () {
    $this->sync->push([listProduct($this->product, 1, 5, ['sellPrice' => 1.60])])->assertOk();
    $late = $this->sync->push([listProduct($this->product, 2, 4, ['sellPrice' => 1.10])])->assertOk();

    expect(TillFixtures::ack($late->json()))->toBe(['acknowledgedSeq' => 2, 'accepted' => 1])
        ->and(DB::table('products')->value('sell_price'))->toEqual('1.60');

    // The portal deletes it; a till's late edit of it arrives afterwards.
    $this->sync->pull(0, bradford: true)->assertOk();
    $this->travel(1)->minutes();
    Pull::portalDelete($this->company, 'Product', $this->product['id']);
    $version = (int) $this->sync->pull(0, bradford: true)->json('highestVersion');
    $this->travel(1)->minutes();
    $this->sync->push([listProduct($this->product, 3, 6, ['sellPrice' => 1.39], ['at' => now('UTC')->toIso8601ZuluString()])])->assertOk();

    expect(DB::table('products')->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('products')->value('sell_price'))->toEqual('1.60')
        ->and(DB::table('sync_conflicts')->count())->toBe(1);

    // Every shop ends with it deleted: the kept row is sent again (a new version, still D), never the till's edit.
    expect(collect(Pull::changes($this->sync->pull(0)))->where('entityId', $this->product['id'])->pluck('op')->all())->toBe(['D'])
        ->and(collect(Pull::changes($this->sync->pull($version, bradford: true)))->pluck('op')->unique()->all())->toBe(['D']);
});

test('19.4 #10: a sale at 23:30 UTC in June counts on the next London trading day, keeping its till time and when it reached us', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 23:31:05', 'UTC'));
    $batch = array_map(function (array $change) {
        $payload = $change['payload'];
        foreach (['completedAt', 'at', 'createdAt', 'updatedAt'] as $time) {
            if (array_key_exists($time, $payload) && $payload[$time] !== null) {
                $payload[$time] = '2026-06-15T23:30:00Z';
            }
        }

        return [...$change, 'at' => '2026-06-15T23:30:00Z', 'payload' => $payload];
    }, array_slice(TillFixtures::sample('push-request.json'), 0, 8));

    $reply = $this->sync->push($batch)->assertOk();

    ($this->asCompany)(function () use ($reply) {
        $sale = Sale::query()->sole();
        $day = fn (string $date) => [CarbonImmutable::parse($date, 'Europe/London'), CarbonImmutable::parse($date, 'Europe/London')->addDay()];

        expect($sale->completed_at?->toIso8601ZuluString())->toBe('2026-06-15T23:30:00Z')
            ->and(Sale::query()->completedBetween(...$day('2026-06-16'))->count())->toBe(1)
            ->and(Sale::query()->completedBetween(...$day('2026-06-15'))->count())->toBe(0)
            ->and(CarbonImmutable::parse((string) DB::table('sales')->value('portal_received_at'), 'UTC')->toIso8601ZuluString())->toBe($reply->json('receivedAt'))
            ->and($reply->json('receivedAt'))->toBe('2026-06-15T23:31:05Z');
    });
});

test('19.4 #14: two tills of one shop take a customer order in the same second: two references, each stored once, keyed by id', function () {
    $sample = collect(TillFixtures::sample('push-request.json'))->firstWhere('entity', 'CustomerOrder');
    $orders = [];

    foreach (['01' => '01K5VC7N2W000000000000W101', '02' => '01K5VC7N2W000000000000W102'] as $till => $id) {
        $orders[] = [...$sample, 'seq' => count($orders) + 1, 'entityId' => $id, 'key' => "CustomerOrder:{$id}:2", 'payload' => [
            ...$sample['payload'], 'id' => $id, 'idempotencyKey' => $id, 'reference' => "CO-LDS-{$till}-000007", 'token' => "K7Q{$till}",
            'createdAt' => '2026-09-29T09:59:59Z', 'updatedAt' => '2026-09-29T09:59:59Z',
        ]];
    }

    $this->sync->push($orders)->assertOk();
    $this->sync->push($orders)->assertOk();

    ($this->asCompany)(function () {
        expect(CustomerOrder::query()->orderBy('reference')->pluck('reference', 'id')->all())->toBe([
            '01K5VC7N2W000000000000W101' => 'CO-LDS-01-000007',
            '01K5VC7N2W000000000000W102' => 'CO-LDS-02-000007',
        ]);
    });

    // Keyed by id, never by reference: a newer version of one order updates that row only.
    $newer = [...$orders[0], 'seq' => 3, 'version' => 3, 'key' => 'CustomerOrder:01K5VC7N2W000000000000W101:3', 'payload' => [...$orders[0]['payload'], 'status' => 'collected', 'rowVersion' => 3]];
    $this->sync->push([$newer])->assertOk();

    expect(DB::table('customer_orders')->count())->toBe(2)
        ->and(DB::table('customer_orders')->where('id', '01K5VC7N2W000000000000W101')->value('status'))->toBe('collected')
        ->and(DB::table('customer_orders')->where('id', '01K5VC7N2W000000000000W102')->value('status'))->toBe('ready');
});
