<?php

namespace App\Domain\Staff\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Staff\Support\TillPermissionCatalogue;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Actions\SetRolePermission;
use App\Domain\TillData\Models\TillRole;
use App\Domain\TillData\Models\TillRolePermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a till role's permission list from the role editor (module 4.5, contract §10.3): only the differences are
 * written, each through SetRolePermission, so every till pulls an `I` per new grant and a `D` per removal. Keys must
 * be in the catalogue (TillPermissionCatalogue); the system Owner role always keeps every permission.
 */
final class SaveRolePermissions
{
    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly SetRolePermission $set,
        private readonly TillPermissionCatalogue $catalogue,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  list<string>  $granted  every key the role should hold after the save
     * @return array{granted: list<string>, removed: list<string>}
     *
     * @throws ValidationException
     */
    public function handle(Company $company, string $roleId, array $granted): array
    {
        return $this->tenancy->runAs($company, fn () => DB::transaction(function () use ($company, $roleId, $granted): array {
            $role = TillRole::query()->find($roleId);

            if ($role === null) {
                throw ValidationException::withMessages(['role' => 'Choose one of this business\'s till roles.']);
            }

            $granted = array_values(array_unique($granted));
            $unknown = array_diff($granted, $this->catalogue->keys());

            if ($unknown !== []) {
                throw ValidationException::withMessages(['permissions' => 'Choose permissions from the list.']);
            }

            $held = TillRolePermission::query()->where('role_id', $role->id)->pluck('permission_key')->all();
            $add = array_values(array_diff($granted, $held));
            $remove = array_values(array_diff($held, $granted));

            foreach ($add as $key) {
                $this->set->handle($company, $role->id, $key, true);
            }

            foreach ($remove as $key) {
                $this->set->handle($company, $role->id, $key, false);
            }

            if ($add !== [] || $remove !== []) {
                $this->audit->handle('till_role.permissions_changed', $role, null, null, ['role' => $role->name, 'granted' => $add, 'removed' => $remove]);
            }

            return ['granted' => $add, 'removed' => $remove];
        }));
    }
}
