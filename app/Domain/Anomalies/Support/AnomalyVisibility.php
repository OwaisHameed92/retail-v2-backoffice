<?php

namespace App\Domain\Anomalies\Support;

use App\Domain\Anomalies\Enums\AnomalyKind;
use App\Domain\Anomalies\Models\Anomaly;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Enums\CompanyRole;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who sees which findings (module 6.6). The page needs `reports.view` (owner, manager, accountant). Staff-level
 * findings name a till user, so only owners and managers see them (privacy); a one-shop user sees only their shop.
 * Only owners and managers change a finding's status.
 */
final class AnomalyVisibility
{
    public static function canView(?CompanyRole $role): bool
    {
        return $role !== null && $role->can(Ability::ReportsView);
    }

    public static function seesStaff(?CompanyRole $role): bool
    {
        return $role === CompanyRole::Owner || $role === CompanyRole::Manager;
    }

    public static function canManage(?CompanyRole $role): bool
    {
        return self::seesStaff($role);
    }

    /**
     * @param  Builder<Anomaly>  $query
     * @return Builder<Anomaly>
     */
    public static function scope(Builder $query, ?CompanyRole $role, ?string $restrictedBranchId): Builder
    {
        if (! self::canView($role)) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->when(! self::seesStaff($role), fn (Builder $q) => $q->whereIn('kind', AnomalyKind::shopLevelValues()))
            ->when($restrictedBranchId !== null, fn (Builder $q) => $q->where('branch_id', $restrictedBranchId));
    }

    /** A finding the current user may see, else 404 (another business's id is not found either). */
    public static function find(string $id, CurrentCompany $tenancy): Anomaly
    {
        return self::scope(Anomaly::query(), $tenancy->role(), $tenancy->restrictedBranchId())->findOrFail($id);
    }

    public static function canSee(Anomaly $anomaly, ?CompanyRole $role, ?string $restrictedBranchId): bool
    {
        return self::canView($role)
            && (! $anomaly->kind->staffLevel() || self::seesStaff($role))
            && ($restrictedBranchId === null || $anomaly->branch_id === $restrictedBranchId);
    }
}
