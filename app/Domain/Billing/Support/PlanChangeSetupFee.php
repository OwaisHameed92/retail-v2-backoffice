<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Collection;

/**
 * The setup fee side of a plan change (owner 2026-10-07). Tills already covered by a setup fee (P11
 * `setup_fee_covered_tills`: charged, paid or waived) are never charged again.
 *
 * - Covered tills: none when no setup fee was ever invoiced or recorded, or when the business only ever had plans
 *   without a setup fee (nothing was paid); otherwise SetupFeeTills::covered (untracked = every till it has).
 * - The new plan has a setup fee and no till is covered: the business's own setup fee ("Setup fee" invoice), the plan
 *   fee once (per business) or × every till (per till).
 * - Some tills covered, a per-till plan: a "Setup fee (added tills)" invoice for the tills not covered (the newest),
 *   at the business's per-till fee, else the plan's; those tills are held until it is paid, as for added tills.
 * - Per business plan with any till covered: nothing (the business already paid its setup fee).
 *
 * The admin may type another amount (net), or 0 to waive it.
 */
final class PlanChangeSetupFee
{
    /**
     * @param  Collection<int, Licence>  $licences  The business's renewable licences.
     * @return array{covered: int, uncovered: int, perTill: bool, suggested: string|null, kind: InvoiceKind|null, licences: list<Licence>}
     */
    public static function work(Company $company, BillingAccount $account, ?Plan $from, Plan $to, int $tills, Collection $licences): array
    {
        $fee = Money::normalise($to->setup_fee ?? '0.00');
        $hasFee = $to->billingType()->hasSetupFee() && ! Money::isZero($fee);
        $perTill = $to->setupFeePerTill();
        $covered = min($tills, self::covered($company, $account, $from));
        $uncovered = max(0, $tills - $covered);
        $none = ['covered' => $covered, 'uncovered' => $uncovered, 'perTill' => $perTill, 'suggested' => null, 'kind' => null, 'licences' => []];

        if (! $hasFee || $uncovered === 0) {
            return $none;
        }

        if ($covered === 0) {
            return [...$none, 'suggested' => $perTill ? Money::mul($fee, $tills) : $fee, 'kind' => InvoiceKind::SetupFee];
        }

        if (! $perTill) {
            return $none;
        }

        $held = SetupFeeTills::heldLicenceIds($company->id);
        $charged = $licences->reject(fn (Licence $licence) => in_array($licence->id, $held, true))->values()->slice(-$uncovered)->values()->all();

        if ($charged === []) {
            return $none;
        }

        $perTillFee = $account->till_setup_fee_override ?? $fee;

        return [...$none, 'suggested' => Money::mul($perTillFee, count($charged)), 'kind' => InvoiceKind::TillSetupFee, 'licences' => $charged];
    }

    /** Tills a setup fee already accounts for (see the class comment). */
    public static function covered(Company $company, BillingAccount $account, ?Plan $from): int
    {
        if ($account->setup_fee_invoiced_at === null && $account->upfront_recorded_at === null) {
            return 0;
        }

        $fromHadFee = $from !== null && $from->billingType()->hasSetupFee() && ! Money::isZero($from->setup_fee ?? '0.00');
        $everCharged = Invoice::withoutCompanyScope()->where('company_id', $company->id)
            ->whereIn('kind', [InvoiceKind::SetupFee->value, InvoiceKind::TillSetupFee->value])
            ->where('status', '!=', InvoiceStatus::Draft->value)->where('total', '>', 0)->exists();

        return $fromHadFee || $everCharged ? SetupFeeTills::covered($company, $account) : 0;
    }

    /**
     * Setup fee invoices still unpaid (issued, part paid or overdue): they stay owed after the change.
     *
     * @return list<Invoice>
     */
    public static function open(Company $company): array
    {
        return Invoice::withoutCompanyScope()->where('company_id', $company->id)
            ->whereIn('kind', [InvoiceKind::SetupFee->value, InvoiceKind::TillSetupFee->value])->open()
            ->orderBy('due_date')->orderBy('sequence')->get()->all();
    }
}
