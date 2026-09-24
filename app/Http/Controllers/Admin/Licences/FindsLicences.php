<?php

namespace App\Http\Controllers\Admin\Licences;

use App\Domain\Licensing\Models\Licence;

/**
 * Admin screens reach licences of every company, so route ids are looked up with the documented
 * `withoutCompanyScope()` escape hatch (never route model binding, which would apply the tenant scope or skip it
 * depending on middleware order).
 */
trait FindsLicences
{
    protected function findLicence(string $id): Licence
    {
        return Licence::withoutCompanyScope()->with(['company', 'branch', 'register', 'plan'])->findOrFail($id);
    }
}
