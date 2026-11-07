<?php

use App\Domain\TillData\Sync\Enums\ChangeOutcome;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/*
 * Till 0.1.85 (WORKSHOP-BIKEBOOK.md): `Product.workshopExposure` is the one member of a hub-owned Product the till owns.
 * Stored from push without overwriting the portal's newer fields, never echoed, never defaulted for an older till, and
 * never pulled while unset. Plus: a till ≤ 0.1.84 push (the v1.4.1 samples) is answered exactly as before.
 */

beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->travelTo('2026-11-08 10:00:00');
    $this->product = TillFixtures::sample('entities/Product.json');                 // 0.1.85: workshopExposure "auto"
    $this->legacy = json_decode((string) file_get_contents(base_path(TillFixtures::LEGACY_CONTRACT.'/samples/entities/Product.json')), true);
    $this->id = $this->product['id'];
    $this->apply = fn (array $payload, int $seq, array $envelope = []) => TillFixtures::apply($this->sync->company, $this->sync->leeds, [TillFixtures::envelope('Product', $payload, $seq, $envelope)]);
    $this->stored = fn () => (array) DB::table('products')->where('id', $this->id)->first();
    $this->pulled = function (bool $bradford) {
        $reply = $this->sync->pull(0, bradford: $bradford)->assertOk();
        expect(SyncApiFixtures::schemaErrors($reply, 'pull-reply.schema.json'))->toBe([]);

        return collect(Pull::changes($reply))->firstWhere('entityId', $this->id);
    };
});

it('stores a till\'s workshopExposure, sends it to the other shops and never back to the till that sent it', function () {
    $this->sync->push([TillFixtures::envelope('Product', [...$this->product, 'workshopExposure' => 'never'], 1)])->assertOk();

    expect(($this->stored)())->toMatchArray(['workshop_exposure' => 'never', 'name' => $this->product['name'], 'origin_branch_id' => $this->sync->leeds->id])
        ->and(($this->stored)()['extra'])->toBeNull()
        ->and(($this->pulled)(true)['payload']['workshopExposure'])->toBe('never')
        ->and(($this->pulled)(false))->toBeNull();
});

it('treats an older till\'s push of the same product as an echo: the till-owned value stays, nothing is sent again', function () {
    ($this->apply)([...$this->product, 'workshopExposure' => 'always'], 1);
    $this->sync->pull(0, bradford: true)->assertOk();                               // stamps the pull version
    $version = ($this->stored)()['hub_version'];

    $echo = ($this->apply)($this->legacy, 2);                                        // a till ≤ 0.1.84: no workshopExposure

    expect($echo->count(ChangeOutcome::Unchanged))->toBe(1)
        ->and(($this->stored)())->toMatchArray(['workshop_exposure' => 'always', 'hub_version' => $version]);
});

it('keeps the portal\'s newer name and price but still takes the till\'s workshopExposure, and sends the result to every till', function () {
    ($this->apply)($this->product, 1);
    $this->travelTo('2026-11-08 11:00:00');
    Pull::portalUpdate($this->sync->company, 'Product', $this->id, ['name' => 'Warburtons Toastie 800g (portal)', 'sell_price' => '1.55']);

    // The till's edit was made before the portal's (no baseVersion: a till before v1.4 semantics): the portal wins.
    $result = ($this->apply)([...$this->product, 'name' => 'Till name', 'sellPrice' => 9.99, 'workshopExposure' => 'always', 'rowVersion' => 2, 'updatedAt' => '2026-11-08T10:30:00Z'], 2, ['at' => '2026-11-08T10:30:00Z']);

    expect($result->count(ChangeOutcome::Conflict))->toBe(1)
        ->and(($this->stored)())->toMatchArray(['name' => 'Warburtons Toastie 800g (portal)', 'workshop_exposure' => 'always', 'hub_version' => null, 'origin_branch_id' => null])
        ->and((float) ($this->stored)()['sell_price'])->toBe(1.55)
        ->and(DB::table('sync_conflicts')->where('entity_id', $this->id)->count())->toBe(1);

    foreach ([false, true] as $bradford) {
        expect(($this->pulled)($bradford)['payload'])->toMatchArray(['name' => 'Warburtons Toastie 800g (portal)', 'workshopExposure' => 'always']);
    }
});

it('never stores a default for a product no 0.1.85 till has touched, never pulls one, and keeps its echo', function () {
    $first = ($this->apply)($this->legacy, 1);
    $again = ($this->apply)($this->legacy, 2);
    $pulled = ($this->pulled)(true);

    expect($first->count(ChangeOutcome::Applied))->toBe(1)
        ->and($again->count(ChangeOutcome::Unchanged))->toBe(1)
        ->and(($this->stored)()['workshop_exposure'])->toBeNull()
        ->and($pulled['payload'])->not->toHaveKey('workshopExposure')
        ->and($pulled['payload']['name'])->toBe($this->legacy['name']);

    // A portal save of such a product keeps the same content hash: the echo of what it sends is still an echo.
    Pull::portalUpdate($this->sync->company, 'Product', $this->id, ['name' => 'Renamed on the portal']);
    $echo = ($this->apply)([...$this->legacy, 'name' => 'Renamed on the portal', 'rowVersion' => 3], 3);

    expect($echo->count(ChangeOutcome::Unchanged))->toBe(1);
});

it('answers a till 0.1.84 push (the v1.4.1 push-request sample) exactly as before, with nothing put in extra', function () {
    $legacy = json_decode((string) file_get_contents(base_path(TillFixtures::LEGACY_CONTRACT.'/samples/push-request.json')), true);
    $expected = json_decode((string) file_get_contents(base_path(TillFixtures::LEGACY_CONTRACT.'/samples/push-reply.json')), true);

    $reply = $this->sync->push($legacy)->assertOk();

    expect(TillFixtures::ack($reply->json()))->toBe(TillFixtures::ack($expected))
        ->and(SyncApiFixtures::schemaErrors($reply, 'push-reply.schema.json'))->toBe([]);

    foreach (['sales', 'sale_lines', 'sale_payments', 'sale_vats', 'stock_movements', 'customer_orders'] as $table) {
        expect(DB::table($table)->whereNotNull('extra')->count())->toBe(0, $table);
    }

    expect(DB::table('customer_orders')->first(['source', 'external_provider', 'external_order_id', 'external_reference']))
        ->toEqual((object) ['source' => 'till', 'external_provider' => null, 'external_order_id' => null, 'external_reference' => '']);
});
