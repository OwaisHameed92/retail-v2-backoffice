<?php

namespace App\Domain\Partners\Actions;

use App\Domain\Mail\Data\PartnerRequestAlertData;
use App\Domain\Partners\Enums\PartnerRequestStatus;
use App\Domain\Partners\Enums\PartnerWebhookType;
use App\Domain\Partners\Models\PartnerTenantRequest;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `POST /api/partner/v1/tenant-requests/{externalId}/cancel` (docs/partner-api.md): the partner's deal is off.
 * A cancelled deal never suspends a live business automatically (owner rule):
 *
 * - `pendingReview` → `cancelled` with the reason, and the `tenant.cancelled` webhook;
 * - `approved` → stays approved, flagged "Partner cancelled the deal" (with the reason) for an admin to act on;
 * - already cancelled or flagged, or rejected → nothing changes (a repeat is harmless).
 *
 * Audited.
 */
class CancelTenantRequest
{
    public const DEFAULT_REASON = 'Cancelled by the partner.';

    public function __construct(
        private readonly QueuePartnerWebhook $webhooks,
        private readonly RecordAudit $audit,
        private readonly AlertStaffOfPartnerRequest $alerts,
    ) {}

    public function handle(PartnerTenantRequest $request, ?string $reason): PartnerTenantRequest
    {
        $reason = trim((string) $reason) === '' ? self::DEFAULT_REASON : mb_substr(trim((string) $reason), 0, 500);

        $flagged = false;

        $result = DB::transaction(function () use ($request, $reason, &$flagged) {
            $locked = PartnerTenantRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $now = CarbonImmutable::now();

            if ($locked->status === PartnerRequestStatus::PendingReview) {
                $locked->status = PartnerRequestStatus::Cancelled;
                $locked->cancelled_at = $now;
                $locked->cancelled_reason = $reason;
                $locked->save();

                $this->audit->handle('partner_request.cancelled', $locked, ['status' => PartnerRequestStatus::PendingReview->value], ['status' => $locked->status->value], [
                    'external_id' => $locked->external_id,
                    'reason' => $reason,
                ]);
                $this->webhooks->handle($locked, PartnerWebhookType::Cancelled);

                return $locked;
            }

            if ($locked->status === PartnerRequestStatus::Approved && ! $locked->partner_cancelled_after_approval) {
                $locked->partner_cancelled_after_approval = true;
                $locked->partner_cancelled_at = $now;
                $locked->cancelled_reason = $reason;
                $locked->save();

                // The business is never touched: an admin decides (it may already be trading).
                $this->audit->handle('partner_request.partner_cancelled', $locked, null, ['partner_cancelled_after_approval' => true], [
                    'external_id' => $locked->external_id,
                    'reason' => $reason,
                ], companyId: $locked->company_id);
                $flagged = true;
            }

            return $locked;
        });

        // An approved business the partner no longer wants: staff decide what happens to it, so they hear at once.
        if ($flagged) {
            $this->alerts->handle($result, null, PartnerRequestAlertData::PARTNER_CANCELLED);
        }

        return $result;
    }
}
