<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Shared\Support\ApiDate;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;

/**
 * The portal's Company, Branch and Register rows in the till's entity shape (field names of
 * docs/contracts/portal-api-v1.1/schemas/entities/{Company,Branch,Register}.schema.json), for the activate reply.
 *
 * - Nulls are written, not omitted. Dates are UTC with `Z`.
 * - Till-owned counters (`nextPoNo`, `nextSaleNo`, `nextRefundNo`) are left out on purpose: the till owns them
 *   and must keep its own values.
 * - `rowVersion` is 0: the portal's sync version counter comes with the pull feed (module 2.5), whose rows
 *   always win over this first copy.
 */
final class TillEntities
{
    /**
     * @return array<string, mixed>
     */
    public static function company(Company $company): array
    {
        return [
            'id' => $company->id,
            'companyId' => $company->id,
            'name' => $company->name,
            'legalName' => $company->legal_name,
            'vatNumber' => $company->vat_number,
            'companyNumber' => $company->company_number,
            'address' => $company->address,
            'phone' => $company->phone,
            'email' => $company->email,
            ...self::row($company),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function branch(Branch $branch): array
    {
        return [
            'id' => $branch->id,
            'companyId' => $branch->company_id,
            'code' => $branch->code,
            'name' => $branch->name,
            'address' => $branch->address,
            'phone' => $branch->phone,
            'vatNumber' => $branch->vat_number,
            'nation' => $branch->nation->value,
            'licensedHoursJson' => $branch->licensed_hours_json,
            'isDrsReturnPoint' => $branch->is_drs_return_point,
            // The contract sends numbers; area is display-only, so a JSON number is safe here.
            'areaM2' => $branch->area_m2 === null ? null : (float) $branch->area_m2,
            ...self::row($branch),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function register(Register $register): array
    {
        return [
            'id' => $register->id,
            'companyId' => $register->company_id,
            'branchId' => $register->branch_id,
            'code' => $register->code,
            'name' => $register->name,
            'isMainTill' => $register->is_main_till,
            'isActive' => $register->is_active,
            ...self::row($register),
        ];
    }

    /**
     * Envelope fields every synced row carries.
     *
     * @return array<string, mixed>
     */
    private static function row(Company|Branch|Register $model): array
    {
        return [
            'createdAt' => ApiDate::format($model->created_at),
            'updatedAt' => ApiDate::format($model->updated_at),
            'rowVersion' => 0,
            'deletedAt' => ApiDate::format($model->deleted_at),
            'isDeleted' => $model->deleted_at !== null,
            'domainEvents' => null,
        ];
    }
}
