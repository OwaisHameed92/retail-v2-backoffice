<?php

namespace Tests\Feature\Handheld;

use Illuminate\Testing\TestResponse;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;
use Tests\Support\ContractSchema;
use Tests\Support\ProposalSchema;

/**
 * Handheld stock drafts (contract proposal pda-stock-drafts) test helpers: the switch, the shop's pull of drafts
 * (checked against the proposal's schemas), and the till's outcome push of a pulled draft.
 */
final class StockDraftFixtures
{
    private static int $seq = 5000;

    public static function on(): void
    {
        config(['handheld.stock_drafts' => true]);
    }

    /**
     * The draft rows of a pull (envelopes of the proposal's entities), each payload checked against the proposal
     * schema and every envelope against sync-change.
     *
     * @return list<array<string, mixed>>
     */
    public static function drafted(TestResponse $pull): array
    {
        $drafted = array_values(array_filter(Pull::changes($pull), fn (array $c) => in_array($c['entity'], [
            'StockAdjustmentDraft', 'StockTake', 'StockTakeLine', 'GoodsReceipt', 'GoodsReceiptLine', 'PurchaseReturn', 'PurchaseReturnLine',
        ], true)));

        foreach ($drafted as $change) {
            expect(ContractSchema::changeErrors($change, $change['entity'], derivedOptional: true))->toBe([])
                ->and(ProposalSchema::payloadErrors($change['payload'], $change['entity']))->toBe([], $change['entity'].' payload');
        }

        return $drafted;
    }

    /**
     * The till's push of its outcome: each pulled row with the given members changed, as the shop's own change
     * (`U`, rowVersion 1, Leeds till 1).
     *
     * @param  list<array{0: array<string, mixed>, 1: array<string, mixed>}>  $rows  [pulled envelope, changed members]
     */
    public static function pushOutcome(SyncApiFixtures $sync, array $rows): TestResponse
    {
        $at = '2026-10-22T09:30:00Z';
        $changes = array_map(function (array $row) use ($at): array {
            [$pulled, $changes] = $row;
            $payload = [...$pulled['payload'], ...$changes, 'rowVersion' => 1, 'updatedAt' => $at];

            return [
                'seq' => ++self::$seq, 'entity' => $pulled['entity'], 'entityId' => $pulled['entityId'], 'op' => 'U', 'version' => 1,
                'companyId' => TillFixtures::COMPANY, 'branchId' => TillFixtures::LEEDS, 'registerId' => '', 'at' => $at,
                'payload' => $payload, 'key' => "{$pulled['entity']}:{$pulled['entityId']}:1",
            ];
        }, $rows);

        return $sync->push($changes);
    }
}
