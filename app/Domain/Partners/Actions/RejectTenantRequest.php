<?php

namespace App\Domain\Partners\Actions;

use App\Domain\Partners\Enums\PartnerRequestStatus;
use App\Domain\Partners\Enums\PartnerWebhookType;
use App\Domain\Partners\Models\PartnerTenantRequest;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Reject" on Admin → Partner requests: a pending request → `rejected` with the reason the partner reads (GET and the
 * `tenant.rejected` webhook). Nothing is created. Audited.
 */
class RejectTenantRequest
{
    public function __construct(
        private readonly QueuePartnerWebhook $webhooks,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(PartnerTenantRequest $request, string $reason, ?string $adminId = null): PartnerTenantRequest
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw ValidationException::withMessages(['reason' => 'Enter the reason the partner will see.']);
        }

        ApproveTenantRequest::ensurePending($request);

        return DB::transaction(function () use ($request, $reason, $adminId) {
            $locked = PartnerTenantRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            ApproveTenantRequest::ensurePending($locked);

            $locked->status = PartnerRequestStatus::Rejected;
            $locked->rejected_at = CarbonImmutable::now();
            $locked->rejected_by_admin_id = $adminId;
            $locked->rejected_reason = mb_substr($reason, 0, 1000);
            $locked->save();

            $this->audit->handle('partner_request.rejected', $locked, ['status' => PartnerRequestStatus::PendingReview->value], ['status' => $locked->status->value], [
                'external_id' => $locked->external_id,
                'reason' => $locked->rejected_reason,
            ]);
            $this->webhooks->handle($locked, PartnerWebhookType::Rejected, (string) $locked->rejected_at->getTimestampMs());

            $request->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }
}
