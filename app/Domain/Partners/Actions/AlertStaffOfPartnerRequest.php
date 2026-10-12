<?php

namespace App\Domain\Partners\Actions;

use App\Domain\Admin\Queries\AdminDashboard;
use App\Domain\Mail\Data\PartnerRequestAlertData;
use App\Domain\Mail\Mailables\AdminPartnerRequestMail;
use App\Domain\Partners\Models\Partner;
use App\Domain\Partners\Models\PartnerTenantRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells staff about a partner request at once (owner 2026-10-12): AdminPartnerRequestMail to the lead alert
 * addresses, and the admin dashboard's cached "Needs attention" is dropped so it shows there now. The admin menu's
 * "Partner requests" count is live already. Called after the request's transaction commits; a mail failure is
 * reported and never fails the partner's call.
 */
class AlertStaffOfPartnerRequest
{
    /** @param  string  $event  PartnerRequestAlertData::NEW | SENT_AGAIN | PARTNER_CANCELLED */
    public function handle(PartnerTenantRequest $request, ?Partner $partner, string $event): void
    {
        AdminDashboard::forget();

        $shops = is_array($request->payload['shops'] ?? null) ? $request->payload['shops'] : [];
        $fee = $request->payload['setupFeePerTill'] ?? null;

        try {
            Mail::queue(new AdminPartnerRequestMail(new PartnerRequestAlertData(
                event: $event,
                requestId: $request->id,
                businessName: $request->business_name,
                partnerName: $partner->name ?? $request->partner->name ?? 'Partner',
                sellerName: $request->seller_name,
                sellerEmail: $request->seller_email,
                shops: count($shops),
                tills: $request->tills(),
                plan: (string) ($request->payload['plan'] ?? ''),
                setupFeePerTill: is_string($fee) || is_numeric($fee) ? (string) $fee : null,
                receivedAt: CarbonImmutable::now(),
                reason: $event === PartnerRequestAlertData::PARTNER_CANCELLED ? $request->cancelled_reason : null,
            )));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
