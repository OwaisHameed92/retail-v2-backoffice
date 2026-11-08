<?php

namespace App\Domain\Sync\Support;

use App\Domain\Salon\Support\SalonBookingFields;
use App\Domain\Salon\Support\SalonPull;
use App\Domain\TillData\Registry\EntityDefinition;
use App\Domain\Workshop\Actions\WriteConnectorStatus;
use App\Domain\Workshop\Support\WorkshopSwitch;
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
 * | Setting                     | company scope: every branch; branch scope: that branch (`scope_id`); W5: never |
 * |                             | `workshop.connector_status` while WORKSHOP_CONNECTOR is off                    |
 * | StockTransfer (+ lines)     | its `toBranchId`, once `dispatched`                                            |
 * | StockTransferReceipt (+ln)  | the `fromBranchId` of its transfer                                             |
 * | CustomerTransaction         | every branch but its own                                                       |
 * | AccountPayDate (0.1.52)     | every branch but its own                                                       |
 * | PurchaseOrder (+ lines)     | head-office orders drafted on the portal, to their shop, until the shop owns it |
 * | Company / Branch            | every branch / that branch (portal edits only)                                 |
 * | Salon* hub rows (W2)        | salon shops only (SalonPull); SalonOnlineBooking only to the shop it names     |
 * | `salon.*` setting / right   | salon shops only                                                               |
 * | a shop row that moved away  | a `D` to the shop it left (BranchDepartures, read by PullFeed)                 |
 */
final class PullVisibility
{
    /**
     * @param  bool  $salon  the branch is a Salon / Barber shop; else no `salon.*` setting or right (SalonPull). PullFeed
     *                       leaves the Salon* tables out of a non-salon shop's feed altogether.
     */
    public static function apply(Builder $query, EntityDefinition $def, string $companyId, string $branchId, bool $salon = true): Builder
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
                ->orWhere(fn (Builder $b) => $b->where('scope', 'branch')->where('scope_id', $branchId)))
                ->when(! $salon, fn (Builder $q) => SalonPull::withoutSalonSettings($q))
                // W5: the portal's `workshop.connector_status` goes down only while WORKSHOP_CONNECTOR is on.
                ->when(! WorkshopSwitch::enabled(), fn (Builder $q) => $q->where('setting_key', '<>', WriteConnectorStatus::KEY)),
            $def->entity === 'RolePermission' && ! $salon => SalonPull::withoutSalonRights($query),
            $def->entity === SalonBookingFields::ENTITY => SalonPull::bookingsFor($query, $branchId),
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
            default => $query, // CustomerTransaction, AccountPayDate: every other branch of the business
        };
    }

    private static function transfer(Builder $query, string $companyId, string $column): Builder
    {
        return $query->selectRaw('1')->from('stock_transfers')
            ->whereColumn('stock_transfers.id', $column)
            ->where('stock_transfers.company_id', $companyId);
    }
}
