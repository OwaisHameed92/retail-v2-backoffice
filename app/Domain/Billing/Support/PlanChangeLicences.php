<?php

namespace App\Domain\Billing\Support;

use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\BranchLicenceTerm;
use App\Domain\Licensing\Support\LicenceGuard;
use App\Domain\Licensing\Support\LicenceTerms;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * The licence side of a plan change (ChangeBusinessPlan, inside its transaction). Every change alters the claims of
 * the till's token, so each till gets a new token (new features, dates) at its next licence check.
 *
 * - move(): every live licence goes onto the new plan; a shop that follows the plan (`branches.licence_features` null)
 *   gets the plan's features, a shop with its own features keeps them; grace days follow the new plan.
 * - From setup only to a recurring plan: endBefore() ends the paid (setup-only) dates the day before the first period
 *   (only while that period is invoiced, so it charges them in full), then bridge() keeps them valid to the first
 *   period's end, so no till locks at the change. Audited `licence.expiry_changed`.
 */
final class PlanChangeLicences
{
    public function __construct(private readonly RecordAudit $audit) {}

    /** Returns how many licences changed. */
    public function move(Company $company, ?Plan $from, Plan $to, CarbonImmutable $now): int
    {
        $changed = 0;

        foreach (Licence::withoutCompanyScope()->whereBelongsTo($company)->live()->orderBy('created_at')->get() as $licence) {
            $locked = LicenceGuard::lock($licence)->load('branch');
            $before = [
                'plan' => $locked->plan?->code,
                'features' => $locked->features->map(fn (Feature $f) => $f->value)->values()->all(),
                'grace_days' => $locked->grace_days,
            ];

            $locked->plan_id = $to->id;
            $locked->setRelation('plan', $to);
            $locked->features = collect($locked->branch !== null ? BranchLicenceTerm::features($locked->branch, $to->features) : Feature::normalise($to->features));
            $locked->grace_days = LicenceTerms::graceDaysFor($locked, $to);
            $locked->status = LicenceTerms::storedStatusAfterDateChange($locked, $now);

            if (! $locked->isDirty()) {
                continue;
            }

            $locked->save();
            $changed++;

            $this->audit->handle('licence.plan_changed', $locked, $before, [
                'plan' => $to->code,
                'features' => $locked->features->map(fn (Feature $f) => $f->value)->values()->all(),
                'grace_days' => $locked->grace_days,
            ], ['from_plan_name' => $from?->name, 'to_plan_name' => $to->name, 'reason' => 'Business plan changed'], companyId: $company->id);
        }

        return $changed;
    }

    /**
     * Ends the licences' paid dates the day before `$start` (not audited: bridge() sets the final date in the same
     * transaction). Returns each licence's paid date before, by id.
     *
     * @param  list<Licence>  $licences
     * @return array<string, CarbonImmutable|null>
     */
    public function endBefore(array $licences, CarbonImmutable $start): array
    {
        $before = [];
        $cut = BillingDates::endOfDay($start->subDay());

        foreach ($licences as $licence) {
            $locked = LicenceGuard::lock($licence);
            $before[$locked->id] = $locked->expires_at;
            $locked->expires_at = $cut;
            $locked->save();
        }

        return $before;
    }

    /**
     * Keeps the licences valid to the end of the first period (23:59:59 local). Returns how many changed.
     *
     * @param  array<string, CarbonImmutable|null>  $before  From endBefore().
     */
    public function bridge(Company $company, array $before, CarbonImmutable $periodEnd, CarbonImmutable $now): int
    {
        $until = BillingDates::endOfDay($periodEnd);
        $count = 0;

        foreach ($before as $id => $was) {
            $locked = Licence::withoutCompanyScope()->lockForUpdate()->findOrFail($id);
            $locked->expires_at = $until;
            $locked->status = LicenceTerms::storedStatusAfterDateChange($locked, $now);
            $locked->save();
            $count++;

            $this->audit->handle('licence.expiry_changed', $locked, ['expires_at' => $was?->toIso8601String()], [
                'expires_at' => $until->toIso8601String(),
                'status' => $locked->status->value,
            ], ['reason' => 'Plan changed to a monthly or yearly fee: valid to the end of the first period'], companyId: $company->id);
        }

        return $count;
    }
}
