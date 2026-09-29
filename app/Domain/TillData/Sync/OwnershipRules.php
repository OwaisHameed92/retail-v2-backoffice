<?php

namespace App\Domain\TillData\Sync;

/**
 * The parts of `samples/ownership.json` beyond hub/branch/local (contract v1.4 §10.1, §10.2, §10.6); a test keeps
 * these lists equal to the file.
 *
 * - RELAYED: branch-owned rows the portal copies, unchanged, to the other branch they concern (transfers to their
 *   `toBranchId`, receipts back to the `fromBranchId`, the customer ledger to every other branch).
 * - DRAFTED: branch-owned tables the portal may also write rows into for one shop (a head-office purchase order);
 *   the shop owns every change after that.
 * - DERIVED_COLUMNS: columns each side works out from a ledger: never taken as the truth from a push, never part of
 *   a row's content hash (so a till's cached balance is not an edit), never written from a pull.
 *
 * Relayed and drafted tables carry `hub_version` and `origin_branch_id` (2026_10_08_100000): a push stores the
 * sending shop as `origin_branch_id`; relayed rows are then stamped for the pull, a shop's own drafted-table rows
 * get `hub_version` 0 (never in the feed).
 */
final class OwnershipRules
{
    public const RELAYED = [
        'StockTransfer', 'StockTransferLine', 'StockTransferReceipt', 'StockTransferReceiptLine', 'CustomerTransaction',
    ];

    public const DRAFTED = ['PurchaseOrder', 'PurchaseOrderLine'];

    public const DERIVED_COLUMNS = ['Customer' => ['balance', 'points']];

    /** Columns added to relayed and drafted tables. */
    public const COPY_COLUMNS = ['hub_version', 'origin_branch_id'];

    /** 'relay', 'draft' or null. */
    public static function copyKind(string $entity): ?string
    {
        return match (true) {
            in_array($entity, self::RELAYED, true) => 'relay',
            in_array($entity, self::DRAFTED, true) => 'draft',
            default => null,
        };
    }

    /**
     * What a push writes into a relayed or drafted table's copy columns: the pushing shop owns the row now. A relayed
     * row is left unstamped for the next pull to relay; a shop's own drafted-table row (its purchase order) is never
     * in the feed (0).
     *
     * @return array<string, mixed>
     */
    public static function copyColumns(?string $kind, string $sendingBranchId): array
    {
        return match ($kind) {
            'relay' => ['hub_version' => null, 'origin_branch_id' => $sendingBranchId],
            'draft' => ['hub_version' => 0, 'origin_branch_id' => $sendingBranchId],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function derivedColumns(string $entity): array
    {
        return self::DERIVED_COLUMNS[$entity] ?? [];
    }
}
