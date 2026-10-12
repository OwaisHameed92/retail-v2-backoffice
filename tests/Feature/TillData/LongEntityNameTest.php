<?php

use App\Domain\TillData\Sync\EnvelopeReader;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/*
 * Sync audit 2026-10-11, SYNC-9: an entity name longer than the store's varchar(64) columns (till_unknown_rows,
 * the push ledger) threw on MySQL outside the per-row fallback, so the whole push answered 500 on every retry.
 * SQLite does not enforce the length, so these tests assert the rejection itself: that one change is
 * `change.invalid` and the rest of the batch still applies.
 */

beforeEach(function () {
    [$this->company, $this->leeds] = TillFixtures::tenant();
    $this->product = TillFixtures::sample('entities/Product.json');
    $this->long = 'PharmacyControlledDrugRegisterWitnessSignatureAuditTrailEntryV2'.str_repeat('X', 10);
});

it('SYNC-9: rejects only the change whose entity name is over 64 characters; the rest of the batch applies', function () {
    $fits = str_repeat('A', EnvelopeReader::MAX_ENTITY_LENGTH);

    $result = TillFixtures::apply($this->company, $this->leeds, [
        TillFixtures::envelope('Product', $this->product, 1),
        TillFixtures::envelope('Product', $this->product, 2, ['entity' => $this->long]),
        TillFixtures::envelope('Product', $this->product, 3, ['entity' => $fits]),
    ]);

    expect(collect($result->rejected)->pluck('code')->all())->toBe(['change.invalid'])
        ->and($result->rejected[0]->seq)->toBe(2)
        ->and($result->rejected[0]->message)->toContain('entity must be at most 64 characters')
        ->and($result->accepted)->toBe(2)
        ->and($result->acknowledgedSeq)->toBe(1)
        ->and(DB::table('products')->where('id', $this->product['id'])->exists())->toBeTrue()
        ->and(DB::table('till_unknown_rows')->pluck('entity')->all())->toBe([$fits]);
});

it('SYNC-9: a push with an over-long entity name never answers 500, and its replies match the contract', function () {
    $sync = new SyncApiFixtures($this);

    // Not the first row: 200, acknowledged up to the row before it.
    $reply = $sync->push([
        TillFixtures::envelope('Product', $this->product, 1),
        TillFixtures::envelope('Product', $this->product, 2, ['entity' => $this->long]),
    ]);

    $reply->assertOk()->assertJsonPath('acknowledgedSeq', 1);
    expect(SyncApiFixtures::schemaErrors($reply, 'push-reply.schema.json'))->toBe([]);

    // The first row: 422 row.invalid naming it, as any other refused change.
    $first = $sync->push([TillFixtures::envelope('Product', $this->product, 5, ['entity' => $this->long])]);

    $first->assertStatus(422)->assertJsonPath('code', 'row.invalid');
    expect(SyncApiFixtures::schemaErrors($first, 'error-reply.schema.json'))->toBe([])
        ->and(DB::table('till_unknown_rows')->count())->toBe(0);
});
