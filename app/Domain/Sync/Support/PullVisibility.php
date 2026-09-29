<?php

namespace App\Domain\Sync\Support;

use App\Domain\TillData\Registry\EntityDefinition;
use Illuminate\Database\Query\Builder;

/**
 * Which rows of the pull feed one branch may receive (contract v1.4.1 §8, §10.2, §10.3, §10.5, §10.6, §19.2).
 * Every rule also means: never a row whose current content this branch pushed (`origin_branch_id`), never a relayed
 * row back to the branch that owns it.
 *
 * | Rows                        | Go to                                                                          |
 * |-----------------------------|--------------------------------------------------------------------------------|
 * | hub-owned, company-wide     | every branch                                                                   |
 * | hub-owned with `branch_id`  | that branch (NewsTitle, BranchPrice), or every branch when blank               |
 * | Setting                     | company scope: every branch; branch scope: that branch (`scope_id`)            |
 * | StockTransfer (+ lines)     | its `toBranchId`, once `dispatched`                                            |
 * | StockTransferReceipt (+ln)  | the `fromBranchId` of its transfer                                             |
 * | CustomerTransaction         | every branch but its own                                                       |
 * | PurchaseOrder (+ lines)     | head-office orders drafted on the portal, to their shop, until the shop owns it |
 * | Company / Branch            | every branch / that branch (portal edits only)                                 |
 */
final class PullVisibility
{
    public static function apply(Builder $query, EntityDefinition $def, string $companyId, string $branchId): Builder
    {
        if ($def->tenancy) {
            return $def->entity === 'Branch' ? $query->where('id', $branchId)->whereNull('deleted_at') : $query;
        }

        $query->where(fn (Builder $q) => $q->whereNull('origin_branch_id')->orWhere('origin_branch_id', '<>', $branchId));

        return match (true) {
            $def->copy === 'relay' => self::relay($query, $def, $companyId, $branchId),
            $def->copy === 'draft' => $query->where('branch_id', $branchId)->whereNotNull('hub_drafted_at')
                ->when($def->entity === 'PurchaseOrder', fn (Builder $q) => $q->where('origin', 'headOffice')),
            $def->entity === 'Setting' => $query->where(fn (Builder $q) => $q->where('scope', 'company')
                ->orWhere(fn (Builder $b) => $b->where('scope', 'branch')->where('scope_id', $branchId))),
            $def->hasScopeColumn('branch_id') => $query->where(
                fn (Builder $w) => $w->whereNull('branch_id')->orWhere('branch_id', '')->orWhere('branch_id', $branchId),
            ),
            default => $query,
        };
    }

    private static function relay(Builder $query, EntityDefinition $def, string $companyId, string $branchId): Builder
    {
        $query->where(fn (Builder $q) => $q->whereNull('branch_id')->orWhere('branch_id', '<>', $branchId));
        $table = $def->table;

        return match ($def->entity) {
            'StockTransfer' => $query->where('status', 'dispatched')->where('to_branch_id', $branchId),
            'StockTransferLine' => $query->whereExists(fn (Builder $t) => self::transfer($t, $companyId, "{$table}.transfer_id")
                ->where('stock_transfers.status', 'dispatched')->where('stock_transfers.to_branch_id', $branchId)),
            'StockTransferReceipt', 'StockTransferReceiptLine' => $query->whereExists(
                fn (Builder $t) => self::transfer($t, $companyId, "{$table}.transfer_id")->where('stock_transfers.from_branch_id', $branchId),
            ),
            default => $query, // CustomerTransaction: every other branch of the business
        };
    }

    private static function transfer(Builder $query, string $companyId, string $column): Builder
    {
        return $query->selectRaw('1')->from('stock_transfers')
            ->whereColumn('stock_transfers.id', $column)
            ->where('stock_transfers.company_id', $companyId);
    }
}
