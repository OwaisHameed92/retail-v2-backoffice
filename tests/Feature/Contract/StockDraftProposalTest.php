<?php

use Tests\Support\ContractSchema;
use Tests\Support\ProposalSchema;

/*
 * Contract proposal docs/contracts/proposals/pda-stock-drafts: its samples are valid JSON, every pulled payload
 * matches the proposal schema (which builds on the v1.4.1 entity schema), the envelopes match v1.4.1 pull-reply /
 * sync-change, and its ownership / enum additions only add (nothing in v1.4.1 is redefined).
 */

function proposalJson(string $file): array
{
    return json_decode((string) file_get_contents(ProposalSchema::path($file)), true, 512, JSON_THROW_ON_ERROR);
}

test('the pull sample is a valid v1.4.1 pull reply and every payload matches the proposal schemas', function () {
    $pull = proposalJson('samples/pull-reply.stock-drafts.json');

    expect(ContractSchema::pullErrors($pull))->toBe([])
        ->and(array_column($pull['changes'], 'entity'))->toBe(['StockAdjustmentDraft', 'StockTake', 'StockTakeLine', 'StockTakeLine', 'GoodsReceipt',
            'GoodsReceiptLine', 'PurchaseReturn', 'PurchaseReturnLine']);

    foreach ($pull['changes'] as $change) {
        expect(ProposalSchema::payloadErrors($change['payload'], $change['entity']))->toBe([], $change['entity']);
    }

    expect(ProposalSchema::payloadErrors(proposalJson('samples/entities/StockAdjustmentDraft.json'), 'StockAdjustmentDraft'))->toBe([]);
});

test('the outcome push sample is a valid v1.4.1 push; the confirmed adjustment matches its schema', function () {
    $push = proposalJson('samples/push-request.stock-drafts-outcome.json');

    expect(ContractSchema::errors($push, 'schemas/push-request.schema.json'))->toBe([]);

    foreach ($push as $change) {
        expect(ContractSchema::changeErrors($change))->toBe([], $change['entity']);
    }

    $adjustment = collect($push)->firstWhere('entity', 'StockAdjustmentDraft');
    expect(ProposalSchema::payloadErrors($adjustment['payload'], 'StockAdjustmentDraft'))->toBe([])
        ->and(array_column($push, 'seq'))->toBe(range(18301, 18309));
});

test('the schemas refuse what a draft must not be (and reach the v1.4.1 schema they build on)', function () {
    $pull = proposalJson('samples/pull-reply.stock-drafts.json')['changes'];
    $take = $pull[1]['payload'];
    $adjustment = $pull[0]['payload'];
    unset($take['name']);

    expect(ProposalSchema::payloadErrors([...$pull[1]['payload'], 'status' => 'approved'], 'StockTake'))->not->toBe([])
        ->and(ProposalSchema::payloadErrors($take, 'StockTake'))->not->toBe([])
        ->and(ProposalSchema::payloadErrors([...$pull[5]['payload'], 'receivedQty' => 0], 'GoodsReceiptLine'))->not->toBe([])
        ->and(ProposalSchema::payloadErrors([...$adjustment, 'reason' => null], 'StockAdjustmentDraft'))->not->toBe([])
        ->and(ProposalSchema::payloadErrors([...$adjustment, 'qtyAfter' => 3], 'StockAdjustmentDraft'))->not->toBe([])
        ->and(ProposalSchema::payloadErrors([...$adjustment, 'status' => 'rejected', 'rejectedAt' => '2026-10-22T09:00:07Z'], 'StockAdjustmentDraft'))->not->toBe([]);
});

test('the ownership and enum additions only add to v1.4.1', function () {
    $ownership = json_decode((string) file_get_contents(ContractSchema::dir('samples/ownership.json')), true, 512, JSON_THROW_ON_ERROR);
    $enums = json_decode((string) file_get_contents(ContractSchema::dir('samples/enums.json')), true, 512, JSON_THROW_ON_ERROR);
    $added = proposalJson('ownership.additions.json');

    expect(array_intersect_key($added['entities'], $ownership['entities']))->toBe([])
        ->and(array_intersect_key($added['hubDrafted'], $ownership['hubDrafted']))->toBe([])
        ->and(array_intersect_key(proposalJson('enums.additions.json'), $enums))->toBe([])
        ->and(array_keys($added['hubDrafted']))->toBe(['StockAdjustmentDraft', 'StockTake', 'StockTakeLine', 'GoodsReceipt', 'GoodsReceiptLine', 'PurchaseReturn', 'PurchaseReturnLine']);

    foreach (array_diff(array_keys($added['hubDrafted']), ['StockAdjustmentDraft']) as $entity) {
        expect($ownership['entities'][$entity])->toBe('branch');
    }
});
