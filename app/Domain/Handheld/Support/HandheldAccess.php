<?php

namespace App\Domain\Handheld\Support;

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;

/**
 * May this handheld user use the app right now? Checked at login and on every call: the business is open (not
 * suspended, cancelled or removed: 403 BUSINESS_SUSPENDED / BUSINESS_CANCELLED), the account is active (403
 * ACCOUNT_INACTIVE) and its shop is open (403 SHOP_CLOSED). Runs before a current company is set, so it reads the
 * rows across companies by the user's own ids (auth code, like the sync keys).
 */
final class HandheldAccess
{
    /**
     * @return array{0: Company, 1: Branch}
     *
     * @throws ApiException
     */
    public static function assertAllowed(HandheldUser $user): array
    {
        $company = Company::withTrashed()->find($user->company_id);

        if ($company === null || $company->trashed() || $company->status === CompanyStatus::Cancelled) {
            throw PdaErrors::cancelled();
        }

        if ($company->status === CompanyStatus::Suspended) {
            throw PdaErrors::suspended();
        }

        if (! $user->is_active) {
            throw PdaErrors::inactive();
        }

        $shop = Branch::withoutCompanyScope()->withTrashed()->where('company_id', $company->id)->find($user->branch_id);

        if (! $shop instanceof Branch || $shop->trashed() || ! $shop->is_active) {
            throw PdaErrors::shopClosed();
        }

        return [$company, $shop];
    }
}
