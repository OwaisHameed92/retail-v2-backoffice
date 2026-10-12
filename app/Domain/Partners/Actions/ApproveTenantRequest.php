<?php

namespace App\Domain\Partners\Actions;

use App\Domain\Billing\Actions\OnboardTenant;
use App\Domain\Billing\Actions\UpdateBillingSettings;
use App\Domain\Billing\Data\BillingSettingsInput;
use App\Domain\Billing\Enums\SetupFeeMethod;
use App\Domain\Billing\GoCardless\Actions\UpdateDirectDebitSettings;
use App\Domain\Billing\GoCardless\Data\DirectDebitSettingsInput;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Partners\Data\PartnerApproval;
use App\Domain\Partners\Enums\PartnerRequestStatus;
use App\Domain\Partners\Enums\PartnerWebhookType;
use App\Domain\Partners\Models\PartnerTenantRequest;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Approve" on Admin → Partner requests: creates the business through the normal onboarding, nothing of its own:
 * OnboardTenant (CreateTenant: company, shops, tills, licences on the plan, owner login, welcome email per the email
 * control settings; OnboardTenantBilling: Direct Debit with its deadline, or manual collection on PK), then the
 * agreed setup fee through the Billing tab's own actions: UpdateDirectDebitSettings (P11: the onboarding fee = fee
 * × tills, `till_setup_fee_override` = fee for tills added later) and UpdateBillingSettings when no VAT is charged.
 * The setup fee payment is recorded later on the Billing tab, as today.
 *
 * One transaction with the request row locked: a double click or a second admin gets a clear error and nothing is
 * made twice. Sends `tenant.approved` (and `tenant.setup_fee_paid` at once when there is nothing to pay). Audited.
 */
class ApproveTenantRequest
{
    public function __construct(
        private readonly OnboardTenant $onboardTenant,
        private readonly UpdateDirectDebitSettings $directDebitSettings,
        private readonly UpdateBillingSettings $billingSettings,
        private readonly BillingAccounts $accounts,
        private readonly QueuePartnerWebhook $webhooks,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(PartnerTenantRequest $request, PartnerApproval $approval, ?string $adminId = null): Company
    {
        self::ensurePending($request);

        return DB::transaction(function () use ($request, $approval, $adminId) {
            $locked = PartnerTenantRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            self::ensurePending($locked);

            $company = $this->onboardTenant->handle($approval->tenant);
            $this->applySetupFee($company, $approval);
            $this->applyVat($company, $approval->vatApplies);

            $locked->status = PartnerRequestStatus::Approved;
            $locked->company_id = $company->id;
            $locked->approved_at = CarbonImmutable::now();
            $locked->approved_by_admin_id = $adminId;
            $locked->save();

            $this->audit->handle('partner_request.approved', $locked, ['status' => PartnerRequestStatus::PendingReview->value], [
                'status' => $locked->status->value,
                'company_id' => $company->id,
            ], [
                'external_id' => $locked->external_id,
                'business' => $company->name,
                'tills' => $approval->tenant->totalTills(),
                'shops' => 1 + count($approval->tenant->moreBranches),
                'setup_fee_per_till' => $approval->setupFeePerTill,
                'vat_applies' => $approval->vatApplies,
            ], companyId: $company->id);

            $this->webhooks->handle($locked, PartnerWebhookType::Approved);

            // Nothing to pay (a £0 fee): the setup fee is settled from the start.
            if (SetupFeeState::for($company, $this->accounts->for($company))->isSettled()) {
                $this->webhooks->handle($locked, PartnerWebhookType::SetupFeePaid);
            }

            $request->setRawAttributes($locked->getAttributes(), true);

            return $company;
        });
    }

    /**
     * @throws ValidationException
     */
    public static function ensurePending(PartnerTenantRequest $request): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages(['status' => match ($request->status) {
                PartnerRequestStatus::Approved => 'This request is already approved.',
                PartnerRequestStatus::Rejected => 'This request was rejected.',
                default => 'The partner cancelled this request.',
            }]);
        }
    }

    private function applySetupFee(Company $company, PartnerApproval $approval): void
    {
        if ($approval->setupFeePerTill === null) {
            return; // the plan's setup fee, as the wizard does without an upfront amount
        }

        $account = $this->accounts->for($company);
        $perTill = Money::normalise($approval->setupFeePerTill);

        $this->directDebitSettings->handle($company, new DirectDebitSettingsInput(
            mode: $account->billing_mode,
            setupFeeOverride: Money::mul($perTill, $approval->tenant->totalTills()),
            setupFeeMethod: SetupFeeMethod::Manual,
            instalments: max(1, $account->setup_fee_instalments),
            tillFeeGiven: true,
            tillSetupFeeOverride: $perTill,
        ));
    }

    private function applyVat(Company $company, bool $vatApplies): void
    {
        $account = $this->accounts->for($company);

        if ($account->vat_applies === $vatApplies) {
            return;
        }

        $this->billingSettings->handle($company, new BillingSettingsInput(
            billingName: $account->billing_name,
            billingAddress: $account->billing_address,
            emails: $account->billing_emails ?? [],
            cycle: $account->cycle,
            paymentTermsDays: $account->payment_terms_days,
            vatApplies: $vatApplies,
        ));
    }
}
