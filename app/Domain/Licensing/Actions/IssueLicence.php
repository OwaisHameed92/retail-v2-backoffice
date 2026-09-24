<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Licensing\Data\IssuedLicence;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Licensing\Support\UniqueLicenceKey;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Domain\Tenancy\Scopes\CompanyScope;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues the licence of one till: a new random key (only its hash and last 4 are stored), status `issued`, the
 * plan's features copied onto the licence. The trial starts on first activation (module 1.5).
 *
 * Rules: the till and its branch are active, the company is not cancelled, the till has no live licence (one
 * licence per till; a revoked one does not count), and the plan is offered (active, not archived). Without a
 * plan the company's plan applies, then the portal default (DefaultPlan).
 */
class IssueLicence
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Register $register, ?Plan $plan = null): IssuedLicence
    {
        return DB::transaction(function () use ($register, $plan) {
            // Lock the till so two requests cannot both issue its licence.
            $register = Register::withoutCompanyScope()->withTrashed()->lockForUpdate()->findOrFail($register->getKey());
            $branch = Branch::withoutCompanyScope()->withTrashed()->findOrFail($register->branch_id);
            $company = Company::withTrashed()->findOrFail($register->company_id);

            $this->ensureCanLicence($company, $branch, $register);
            $plan = $this->resolvePlan($company, $plan);

            $key = UniqueLicenceKey::generate();

            try {
                $licence = new Licence([
                    'company_id' => $company->id,
                    'branch_id' => $branch->id,
                    'register_id' => $register->id,
                    'plan_id' => $plan->id,
                    'key_hash' => $key->hash(),
                    'key_last4' => $key->last4(),
                    'status' => LicenceStatus::Issued,
                    'features' => $plan->features,
                    'grace_days' => $plan->trial_grace_days,
                ]);
                $licence->save();
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['register' => "{$register->name} already has a licence."]);
            }

            $this->audit->handle('licence.issued', $licence, null, [
                'id' => $licence->id,
                'register_id' => $register->id,
                'branch_id' => $branch->id,
                'plan' => $plan->code,
                'status' => $licence->status->value,
                'key_last4' => $licence->key_last4,
            ], ['till' => "{$register->name} ({$register->code})", 'branch' => $branch->name]);

            return new IssuedLicence($licence, $key);
        });
    }

    /**
     * @throws ValidationException
     */
    private function ensureCanLicence(Company $company, Branch $branch, Register $register): void
    {
        if ($company->trashed() || $company->isCancelled()) {
            throw ValidationException::withMessages(['register' => "{$company->name} is cancelled. Reinstate it before issuing licences."]);
        }

        if ($branch->trashed() || ! $branch->is_active) {
            throw ValidationException::withMessages(['register' => "{$branch->name} is inactive. Reactivate the branch before issuing licences."]);
        }

        if ($register->trashed() || ! $register->is_active) {
            throw ValidationException::withMessages(['register' => "{$register->name} is deactivated. Reactivate the till before issuing its licence."]);
        }

        $live = Licence::query()->withoutGlobalScope(CompanyScope::class)->live()->where('register_id', $register->id)->exists();

        if ($live) {
            throw ValidationException::withMessages(['register' => "{$register->name} already has a licence. Reissue its key or revoke it first."]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function resolvePlan(Company $company, ?Plan $plan): Plan
    {
        $plan ??= DefaultPlan::for($company);

        if ($plan === null) {
            throw ValidationException::withMessages(['plan' => 'There is no active plan to licence tills with. Create a plan first.']);
        }

        if (! DefaultPlan::isOffered($plan)) {
            throw ValidationException::withMessages(['plan' => "{$plan->name} is not offered any more. Choose an active plan."]);
        }

        return $plan;
    }
}
