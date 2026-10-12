<?php

namespace App\Domain\Partners\Actions;

use App\Domain\Mail\Data\PartnerRequestAlertData;
use App\Domain\Partners\Data\TenantRequestInput;
use App\Domain\Partners\Enums\PartnerRequestStatus;
use App\Domain\Partners\Models\Partner;
use App\Domain\Partners\Models\PartnerTenantRequest;
use App\Domain\Partners\Support\PartnerApiErrors;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Exceptions\ApiException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * `PUT /api/partner/v1/tenant-requests/{externalId}`, idempotent by externalId (one request per partner externalId,
 * ever; docs/partner-api.md):
 *
 * - new → stored as `pendingReview` (201);
 * - same body as stored (except while rejected) → unchanged (200);
 * - changed body while `pendingReview` → updated (200), the admin reviews the new one;
 * - any body while `rejected` (sent again) → reopened as `pendingReview` with that body (200, audited);
 * - changed body once approved → 409 partner.request_locked (edits are made on the portal); once cancelled → 409
 *   partner.request_closed (final).
 *
 * Never creates a business. Audited (`partner_request.received` / `partner_request.updated`).
 */
class SubmitTenantRequest
{
    public function __construct(
        private readonly RecordAudit $audit,
        private readonly AlertStaffOfPartnerRequest $alerts,
    ) {}

    /**
     * @return array{0: PartnerTenantRequest, 1: 'created'|'updated'|'unchanged'}
     *
     * @throws ApiException
     */
    public function handle(Partner $partner, string $externalId, TenantRequestInput $input): array
    {
        try {
            [$request, $result, $alert] = $this->apply($partner, $externalId, $input);
        } catch (UniqueConstraintViolationException) {
            // The same externalId arrived twice at once: the other call created it; this one is now a repeat.
            [$request, $result, $alert] = $this->apply($partner, $externalId, $input);
        }

        // Staff hear of a new request (or one sent again) at once, after the commit (owner 2026-10-12).
        if ($alert !== null) {
            $this->alerts->handle($request, $partner, $alert);
        }

        return [$request, $result];
    }

    /**
     * @return array{0: PartnerTenantRequest, 1: 'created'|'updated'|'unchanged', 2: PartnerRequestAlertData::NEW|PartnerRequestAlertData::SENT_AGAIN|null}
     */
    private function apply(Partner $partner, string $externalId, TenantRequestInput $input): array
    {
        return DB::transaction(function () use ($partner, $externalId, $input) {
            $request = PartnerTenantRequest::query()->where('partner_id', $partner->id)->where('external_id', $externalId)->lockForUpdate()->first();
            $hash = $input->hash();

            if ($request === null) {
                $request = PartnerTenantRequest::query()->create([
                    'partner_id' => $partner->id,
                    'external_id' => $externalId,
                    'status' => PartnerRequestStatus::PendingReview,
                    'payload' => $input->payload,
                    'payload_hash' => $hash,
                    'business_name' => $input->businessName(),
                    'seller_name' => $input->sellerName(),
                    'seller_email' => $input->sellerEmail(),
                ]);

                $this->audit->handle('partner_request.received', $request, null, ['status' => $request->status->value], [
                    'partner' => $partner->name,
                    'external_id' => $externalId,
                    'business' => $request->business_name,
                    'seller' => $request->seller_email,
                    'tills' => $request->tills(),
                ]);

                return [$request->refresh(), 'created', PartnerRequestAlertData::NEW];
            }

            if (hash_equals($request->payload_hash, $hash) && $request->status !== PartnerRequestStatus::Rejected) {
                return [$request, 'unchanged', null];
            }

            if ($request->status === PartnerRequestStatus::Approved) {
                throw PartnerApiErrors::locked('This request is approved and the business exists: changes are made on the retail portal.');
            }

            if ($request->status === PartnerRequestStatus::Cancelled) {
                throw PartnerApiErrors::closed();
            }

            $reopened = $request->status === PartnerRequestStatus::Rejected;

            if ($reopened) {
                // Sent again after a rejection: back to review with the new body (the old reason stays in the audit log).
                $request->status = PartnerRequestStatus::PendingReview;
                $request->rejected_at = null;
                $request->rejected_by_admin_id = null;
                $request->rejected_reason = null;
            }

            $request->fill([
                'payload' => $input->payload,
                'payload_hash' => $hash,
                'business_name' => $input->businessName(),
                'seller_name' => $input->sellerName(),
                'seller_email' => $input->sellerEmail(),
            ])->save();

            $this->audit->handle($reopened ? 'partner_request.reopened' : 'partner_request.updated', $request, $reopened ? ['status' => PartnerRequestStatus::Rejected->value] : null, $reopened ? ['status' => PartnerRequestStatus::PendingReview->value] : null, [
                'partner' => $partner->name,
                'external_id' => $externalId,
                'business' => $request->business_name,
                'tills' => $request->tills(),
            ]);

            return [$request, 'updated', $reopened ? PartnerRequestAlertData::SENT_AGAIN : null];
        });
    }
}
