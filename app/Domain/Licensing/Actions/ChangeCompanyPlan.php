<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets the plan a company's new tills are licensed on. With `$applyToLicences`, every live licence of the
 * company moves to it too (ChangeLicencePlan). Returns how many licences changed plan.
 */
class ChangeCompanyPlan
{
    public function __construct(
        private readonly ChangeLicencePlan $changeLicencePlan,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, Plan $plan, bool $applyToLicences = false): int
    {
        if (! DefaultPlan::isOffered($plan)) {
            throw ValidationException::withMessages(['plan_id' => "{$plan->name} is not offered any more. Choose an active plan."]);
        }

        return DB::transaction(function () use ($company, $plan, $applyToLicences) {
            $company->refresh();
            $old = $company->plan;

            if ($company->plan_id !== $plan->id) {
                $company->plan_id = $plan->id;
                $company->save();

                $this->audit->handle('company.plan_changed', $company, ['plan' => $old?->code], ['plan' => $plan->code], [
                    'from_plan_name' => $old?->name,
                    'to_plan_name' => $plan->name,
                ], companyId: $company->id);
            }

            if (! $applyToLicences) {
                return 0;
            }

            $changed = 0;
            $licences = Licence::withoutCompanyScope()->whereBelongsTo($company)->live()->where('plan_id', '!=', $plan->id)->get();

            foreach ($licences as $licence) {
                $changed += $this->changeLicencePlan->handle($licence, $plan)->changed ? 1 : 0;
            }

            return $changed;
        });
    }
}
