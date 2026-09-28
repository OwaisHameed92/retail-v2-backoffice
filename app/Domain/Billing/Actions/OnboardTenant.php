<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Tenancy\Actions\CreateTenant;
use App\Domain\Tenancy\Data\NewTenant;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The admin "New tenant" wizard (module 1.13): CreateTenant and its billing (OnboardTenantBilling: Direct Debit
 * with the setup deadline, plus the upfront payment when staff took one) in one transaction, so a refused payment
 * creates nothing.
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
        return DB::transaction(function () use ($tenant, $upfront) {
            $company = $this->createTenant->handle($tenant);
            $this->onboardBilling->handle($company, $upfront);

            return $company;
        });
    }
}
