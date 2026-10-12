<?php

namespace App\Domain\Partners\Data;

use App\Domain\Partners\Enums\PartnerRequestStatus;
use App\Domain\Partners\Models\Partner;
use App\Domain\Partners\Models\PartnerKey;
use App\Domain\Partners\Support\PartnerKeySecret;

/**
 * Admin → Settings → Partners: every partner with its webhook settings (never the secret itself: whether one is set
 * and its last 4), its keys (name, last 4, created, last used, revoked) and how many requests it sent.
 */
final class PartnerSettingsData
{
    /**
     * @return array{partners: list<array<string, mixed>>, apiBase: string}
     */
    public static function all(): array
    {
        $partners = Partner::query()->with(['keys' => fn ($q) => $q->orderByDesc('created_at')])
            ->withCount([
                'tenantRequests',
                'tenantRequests as pending_requests_count' => fn ($q) => $q->where('status', PartnerRequestStatus::PendingReview->value),
            ])
            ->orderBy('name')->get();

        return [
            'partners' => $partners->map(fn (Partner $partner) => [
                'id' => $partner->id,
                'name' => $partner->name,
                'webhookUrl' => $partner->webhook_url,
                'hasSecret' => $partner->webhook_secret !== null,
                'secretEnding' => $partner->webhook_secret_last4,
                'isActive' => $partner->is_active,
                'requests' => (int) $partner->getAttribute('tenant_requests_count'),
                'pendingRequests' => (int) $partner->getAttribute('pending_requests_count'),
                'createdAt' => $partner->created_at?->toIso8601String(),
                'keys' => $partner->keys->map(fn (PartnerKey $key) => [
                    'id' => $key->id,
                    'name' => $key->name,
                    'masked' => PartnerKeySecret::mask($key->key_last4),
                    'createdAt' => $key->created_at?->toIso8601String(),
                    'lastUsedAt' => $key->last_used_at?->toIso8601String(),
                    'revokedAt' => $key->revoked_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
            'apiBase' => url('/api/partner/v1'),
        ];
    }
}
