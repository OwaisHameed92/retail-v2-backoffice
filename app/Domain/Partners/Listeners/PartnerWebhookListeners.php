<?php

namespace App\Domain\Partners\Listeners;

use App\Domain\Billing\Events\SetupFeePaymentRecorded;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Licensing\Events\LicenceActivated;
use App\Domain\Partners\Actions\QueuePartnerWebhook;
use App\Domain\Partners\Enums\PartnerRequestStatus;
use App\Domain\Partners\Enums\PartnerWebhookType;
use App\Domain\Partners\Models\PartnerTenantRequest;
use App\Domain\Partners\Support\PartnerApi;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Events\CompanyCancelled;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The partner webhooks that follow the business after approval, only for businesses made from a partner request
 * (one indexed lookup by company; nothing at all for every other business). Each runs inside the caller's
 * transaction, so the outbox row commits with the payment, the activation or the cancellation; a failure here is
 * reported and never breaks that flow.
 *
 * - SetupFeePaymentRecorded → `tenant.setup_fee_paid` once the setup fee is settled (every instalment paid);
 * - LicenceActivated → `tenant.first_till_activated` (the first only: one event per request and type);
 * - CompanyCancelled → the approved request becomes `cancelled` and `tenant.cancelled` goes out.
 */
class PartnerWebhookListeners
{
    public function __construct(
        private readonly QueuePartnerWebhook $webhooks,
        private readonly BillingAccounts $accounts,
        private readonly RecordAudit $audit,
    ) {}

    public function setupFeePaid(SetupFeePaymentRecorded $event): void
    {
        $this->safely(function () use ($event) {
            $request = self::approvedRequestFor($event->companyId);
            $company = $request !== null ? Company::query()->find($event->companyId) : null;

            if ($request !== null && $company !== null && SetupFeeState::for($company, $this->accounts->for($company))->isSettled()) {
                $this->webhooks->handle($request, PartnerWebhookType::SetupFeePaid);
            }
        });
    }

    public function firstTillActivated(LicenceActivated $event): void
    {
        $this->safely(function () use ($event) {
            $request = self::approvedRequestFor($event->licence->company_id);

            if ($request !== null) {
                $this->webhooks->handle($request, PartnerWebhookType::FirstTillActivated);
            }
        });
    }

    public function companyCancelled(CompanyCancelled $event): void
    {
        $this->safely(function () use ($event) {
            $request = self::approvedRequestFor($event->company->id);

            if ($request === null) {
                return;
            }

            $request->status = PartnerRequestStatus::Cancelled;
            $request->cancelled_at = CarbonImmutable::now();
            $request->cancelled_reason ??= 'The business was cancelled on the retail portal.';
            $request->save();

            $this->audit->handle('partner_request.business_cancelled', $request, ['status' => PartnerRequestStatus::Approved->value], ['status' => $request->status->value], [
                'external_id' => $request->external_id,
                'reason' => $event->reason,
            ], companyId: $event->company->id);
            $this->webhooks->handle($request, PartnerWebhookType::Cancelled);
        });
    }

    private static function approvedRequestFor(string $companyId): ?PartnerTenantRequest
    {
        return PartnerTenantRequest::query()->where('company_id', $companyId)
            ->where('status', PartnerRequestStatus::Approved->value)->first();
    }

    private function safely(callable $work): void
    {
        if (! PartnerApi::enabled()) {
            return; // UK only
        }

        try {
            DB::transaction(fn () => $work());
        } catch (Throwable $e) {
            report($e);
        }
    }
}
