<?php

namespace App\Domain\Partners\Support;

use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Billing\Support\SetupFeeTills;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Partners\Models\PartnerTenantRequest;
use App\Domain\Shared\Support\ApiDate;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The tenant request as the partner reads it (`GET …/tenant-requests/{externalId}`, every reply of the API and the
 * `data` of every webhook; docs/partner-api.md). Before approval there is no business: `tenantId`, `setupFee` and
 * `firstActivatedAt` are null and `tillsActivated` is 0. Money is VAT inclusive, dates ISO-8601 UTC; `createdAt` and
 * `updatedAt` have milliseconds and `updatedAt` only moves forward (PartnerTenantRequest::touchForward).
 */
final class TenantRequestView
{
    /**
     * @return array<string, mixed>
     */
    public static function of(PartnerTenantRequest $request): array
    {
        $company = $request->company_id !== null ? Company::withTrashed()->find($request->company_id) : null;
        $activations = $company !== null
            ? Licence::withoutCompanyScope()->where('company_id', $company->id)->whereNotNull('activated_at')
            : null;

        return [
            'externalId' => $request->external_id,
            'status' => $request->status->value,
            'tenantId' => $company?->id,
            'businessName' => $company !== null ? $company->name : $request->business_name,
            'tills' => $company !== null ? SetupFeeTills::count($company) : $request->tills(),
            'setupFee' => $company !== null ? self::setupFee($company) : null,
            'tillsActivated' => $activations?->count() ?? 0,
            'firstActivatedAt' => ApiDate::format($activations?->min('activated_at')),
            // The business on the portal (admin area): GET /admin/tenants/{tenantId}.
            'adminUrl' => $company !== null ? route('admin.tenants.show', $company) : null,
            'rejectedReason' => $request->rejected_reason,
            'cancelledReason' => $request->cancelled_reason,
            'partnerCancelled' => $request->partner_cancelled_after_approval,
            'createdAt' => self::iso($request->created_at),
            'updatedAt' => self::iso($request->updated_at),
        ];
    }

    /** ISO-8601 UTC with milliseconds ("2026-10-12T09:00:00.000Z"): the ordering stamps of requests and events. */
    public static function iso(?CarbonInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * @return array{total: string, paid: bool, paidAt: string|null}
     */
    public static function setupFee(Company $company): array
    {
        $account = app(BillingAccounts::class)->for($company);
        $state = SetupFeeState::for($company, $account);
        $paid = $state->isSettled();
        $paidAt = null;

        if ($paid) {
            $lastPaid = Invoice::withoutCompanyScope()->where('company_id', $company->id)
                ->where('kind', InvoiceKind::SetupFee->value)->where('status', InvoiceStatus::Paid->value)
                ->max('paid_at');
            $paidAt = ApiDate::format($lastPaid ?? $account->upfront_recorded_at ?? $account->setup_fee_invoiced_at);
        }

        return ['total' => $state->total, 'paid' => $paid, 'paidAt' => $paidAt];
    }
}
