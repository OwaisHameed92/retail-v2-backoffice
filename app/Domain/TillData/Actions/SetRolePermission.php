<?php

namespace App\Domain\TillData\Actions;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\TillRole;
use App\Domain\TillData\Models\TillRolePermission;
use App\Domain\TillData\Sync\SyncRowIds;
use Illuminate\Validation\ValidationException;

/**
 * Grants or takes away one till permission of a role on the portal (contract v1.4 §10.3): a keyed row per (role,
 * permission), stored under the id derived from it (SyncRowIds). A grant is pulled as `I`, a removal as `D` (soft
 * delete); a grant again restores the row. The till's system Owner role always keeps every permission, so taking one
 * from it is refused here as it is on the till. The role editor (Phase 4) calls this.
 *
 *     app(SetRolePermission::class)->handle($company, $roleId, 'sale.refund', granted: false);
 */
final class SetRolePermission
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, string $roleId, string $permissionKey, bool $granted): ?TillRolePermission
    {
        $permissionKey = trim($permissionKey);

        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $permissionKey) !== 1) {
            throw ValidationException::withMessages(['permission' => 'Choose a permission from the list.']);
        }

        return $this->tenancy->runAs($company, function () use ($roleId, $permissionKey, $granted): ?TillRolePermission {
            $role = TillRole::query()->find($roleId);

            if ($role === null) {
                throw ValidationException::withMessages(['role' => 'Choose one of this business\'s till roles.']);
            }

            if (! $granted && $role->is_system && strcasecmp($role->name, 'Owner') === 0) {
                throw ValidationException::withMessages(['role' => 'The Owner role always keeps every permission.']);
            }

            $id = SyncRowIds::rolePermission($roleId, $permissionKey);
            $row = TillRolePermission::withTrashed()->find($id);

            if (! $granted) {
                if ($row !== null && ! $row->trashed()) {
                    $row->delete();
                }

                return $row;
            }

            if ($row !== null && ! $row->trashed()) {
                return $row;
            }

            $row ??= new TillRolePermission;
            $row->forceFill(['id' => $id, 'role_id' => $roleId, 'permission_key' => $permissionKey, 'deleted_at' => null])->save();

            return $row;
        });
    }
}
