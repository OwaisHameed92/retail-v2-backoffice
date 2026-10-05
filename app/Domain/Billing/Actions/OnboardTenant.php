<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\NewTenant;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The admin "New tenant" wizard (module 1.13): CreateTenant and its billing (OnboardTenantBilling: Direct Debit
 * with the setup deadline, plus the upfront payment when staff took one) in one transaction, so a refused payment
 * creates nothing. Like ApproveTrial, it needs a plan: tills from 0.1.35 have no built-in trial and lock without a
 * genuine key, so every new business must get its keys by email (SendWelcomeEmailWithKeys).
 */
class OnboardTenant
{
    public function __construct(
        private readonly CreateTenant $createTenant,
        private readonly OnboardTenantBilling $onboardBilling,
    ) {}

    /** @throws ValidationException */
    public function handle(NewTenant $tenant, ?UpfrontPayment $upfront = null): Company
    {
        if ($tenant->planId === null && DefaultPlan::portal() === null) {
            throw ValidationException::withMessages(['plan_id' => 'There is no active plan yet, so the tills would have no licence keys. Create a plan first.']);
        }

        return DB::transaction(function () use ($tenant, $upfront) {
            $company = $this->createTenant->handle($tenant);
            $this->onboardBilling->handle($company, $upfront);

            return $company;
        });
    }
}
