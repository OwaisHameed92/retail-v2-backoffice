<?php

namespace App\Domain\Partners\Data;

use App\Domain\Admin\Models\Admin;
use App\Domain\Partners\Models\PartnerTenantRequest;
use App\Domain\Partners\Models\PartnerWebhookEvent;
use App\Domain\Partners\Queries\PartnerRequestList;
use App\Domain\Shared\Models\AuditLog;

/**
 * Admin → Partner requests → one request: what the partner sent, the seller, the partner, the business made from it,
 * the webhooks sent about it and its activity (audit entries of the request).
 */
final class PartnerRequestDetail
{
    /**
     * @return array<string, mixed>
     */
    public static function for(PartnerTenantRequest $request): array
    {
        $request->loadMissing(['partner', 'company', 'approvedBy', 'rejectedBy']);

        return [
            'request' => PartnerRequestList::row($request) + [
                'payload' => $request->payload,
                'approvedAt' => $request->approved_at?->toIso8601String(),
                'approvedBy' => $request->approvedBy?->name,
                'rejectedAt' => $request->rejected_at?->toIso8601String(),
                'rejectedBy' => $request->rejectedBy?->name,
                'rejectedReason' => $request->rejected_reason,
                'cancelledAt' => $request->cancelled_at?->toIso8601String(),
                'cancelledReason' => $request->cancelled_reason,
                'partnerCancelledAt' => $request->partner_cancelled_at?->toIso8601String(),
                'company' => $request->company === null ? null : [
                    'id' => $request->company->id,
                    'name' => $request->company->name,
                    'status' => $request->company->status->value,
                    'deleted' => $request->company->trashed(),
                ],
            ],
            'webhooks' => $request->webhookEvents()->orderBy('created_at')->get()->map(fn (PartnerWebhookEvent $event) => [
                'id' => $event->id,
                'type' => $event->type->value,
                'label' => $event->type->label(),
                'attempts' => $event->attempts,
                'lastStatus' => $event->last_status,
                'lastError' => $event->last_error,
                'deliveredAt' => $event->delivered_at?->toIso8601String(),
                'failedAt' => $event->failed_at?->toIso8601String(),
                'nextAttemptAt' => $event->next_attempt_at?->toIso8601String(),
                'createdAt' => $event->created_at?->toIso8601String(),
            ])->values(),
            'activity' => self::activity($request),
        ];
    }

    /**
     * @return list<array{id: string, action: string, actor: string|null, at: string|null, reason: string|null}>
     */
    private static function activity(PartnerTenantRequest $request): array
    {
        $entries = AuditLog::query()->where('subject_type', $request->getMorphClass())->where('subject_id', $request->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get();
        $admins = Admin::query()->whereIn('id', $entries->where('actor_type', (new Admin)->getMorphClass())->pluck('actor_id')->filter()->unique())->pluck('name', 'id');

        return $entries->map(fn (AuditLog $entry) => [
            'id' => $entry->id,
            'action' => $entry->action,
            'actor' => $entry->actor_id !== null ? ($admins[$entry->actor_id] ?? null) : null,
            'at' => $entry->created_at?->toIso8601String(),
            'reason' => is_string($entry->meta['reason'] ?? null) ? $entry->meta['reason'] : null,
        ])->values()->all();
    }
}
