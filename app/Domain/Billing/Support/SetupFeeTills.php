<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;

/**
 * Per-till setup fee (P11, owner 2026-10-07): how many tills a business has, whether its plan charges the setup fee
 * for each till, how many tills a setup fee already covers, and which licences wait for an added till's setup fee.
 *
 * - Tills: the active tills of the business's active branches.
 * - Covered tills (`billing_accounts.setup_fee_covered_tills`): tills a setup fee accounts for, charged, paid or
 *   waived. Null = never tracked: the tills the business already has count as covered (grandfathered), so nobody is
 *   charged again for a till they have. `billing:backfill-setup-fee-coverage` fills it for paid businesses.
 * - Held licence: a till whose added-till setup fee invoice is issued and not paid (nor voided). It stays on its trial:
 *   billing never extends it until the invoice is paid.
 */
final class SetupFeeTills
{
    /** Active tills in active branches. */
    public static function count(Company $company): int
    {
        return Register::withoutCompanyScope()->where('company_id', $company->id)->where('is_active', true)
            ->whereIn('branch_id', Branch::withoutCompanyScope()->select('id')->where('company_id', $company->id)->where('is_active', true))
            ->count();
    }

    /** The business's plan charges the setup fee for each till. */
    public static function perTill(Company $company): bool
    {
        return DefaultPlan::for($company)?->setupFeePerTill() ?? false;
    }

    /** Tills already covered by a setup fee; untracked = every till the business has now. */
    public static function covered(Company $company, BillingAccount $account): int
    {
        return $account->setup_fee_covered_tills ?? self::count($company);
    }

    /** Marks every till the business has now as covered (never lowers it). The caller saves. */
    public static function coverAll(Company $company, BillingAccount $account): void
    {
        $account->setup_fee_covered_tills = max((int) ($account->setup_fee_covered_tills ?? 0), self::count($company));
    }

    /**
     * Licences of a business held by an unpaid added-till setup fee.
     *
     * @return list<string>
     */
    public static function heldLicenceIds(string $companyId): array
    {
        $open = [InvoiceStatus::Draft->value, ...InvoiceStatus::openValues()];

        return InvoiceLine::withoutCompanyScope()->where('company_id', $companyId)->whereNotNull('licence_id')
            ->whereIn('invoice_id', Invoice::withoutCompanyScope()->select('id')->where('company_id', $companyId)
                ->where('kind', InvoiceKind::TillSetupFee->value)->whereIn('status', $open))
            ->distinct()->pluck('licence_id')->map(fn ($id) => (string) $id)->values()->all();
    }

    public static function isHeld(Licence $licence): bool
    {
        return in_array($licence->id, self::heldLicenceIds($licence->company_id), true);
    }
}
