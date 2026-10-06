<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Data\PricingOverride;
use App\Domain\Billing\GoCardless\Actions\SyncSubscription;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Country\MoneyFormat;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\AuditChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Staff set a company's own pricing (module 1.13, `billing.manage`): per till or per branch and the monthly /
 * yearly price per unit, each blank for the plan's. Audited (`billing.pricing_updated`). Invoices raised from now
 * on use it, and a live Direct Debit subscription changes its amount from the next payment.
 */
class UpdateCompanyPricing
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly SyncSubscription $syncSubscription,
        private readonly RecordAudit $audit,
    ) {}

    /** @throws ValidationException */
    public function handle(Company $company, PricingOverride $input): BillingAccount
    {
        foreach (['price_monthly_override' => $input->priceMonthly, 'price_yearly_override' => $input->priceYearly] as $field => $price) {
            if ($price !== null && Money::isNegative($price)) {
                throw ValidationException::withMessages([$field => 'The price cannot be below '.MoneyFormat::format('0').'.']);
            }
        }

        $changed = DB::transaction(function () use ($company, $input) {
            $account = $this->accounts->lock($company);
            $account->pricing_mode_override = $input->mode;
            $account->price_monthly_override = $input->priceMonthly === null ? null : Money::normalise($input->priceMonthly);
            $account->price_yearly_override = $input->priceYearly === null ? null : Money::normalise($input->priceYearly);

            [$before, $after] = AuditChanges::of($account);

            if ($after === []) {
                return false;
            }

            $account->save();
            $this->audit->handle('billing.pricing_updated', $account, $before, $after, companyId: $company->id);

            return true;
        });

        if ($changed) {
            try {
                $this->syncSubscription->handle($company, 'pricing');
            } catch (GoCardlessException $exception) {
                // The daily reconcile corrects the amount; staff can also press "Update subscription".
                Log::warning('Direct Debit amount not updated after a pricing change', ['company_id' => $company->id, 'error' => $exception->getMessage()]);
            }
        }

        return $this->accounts->for($company);
    }
}
