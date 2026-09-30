<?php

namespace App\Http\Requests\App\Setup;

/** Saving a till role's permission list (module 4.5). Route: `company.can:staff.manage`. */
class RolePermissionsRequest extends CompanyWideWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array', 'max:500'],
            'permissions.*' => ['string', 'max:100'],
        ];
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return array_values(array_map('strval', (array) $this->input('permissions', [])));
    }
}
