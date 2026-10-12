<?php

namespace App\Domain\Partners\Actions;

use App\Domain\Partners\Enums\PartnerWebhookType;
use App\Domain\Partners\Jobs\SendPartnerWebhookJob;
use App\Domain\Partners\Models\PartnerTenantRequest;
use App\Domain\Partners\Models\PartnerWebhookEvent;
use App\Domain\Partners\Support\PartnerApi;
use App\Domain\Partners\Support\TenantRequestView;
use App\Domain\Shared\Support\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Puts a webhook in the outbox: `{id, type, createdAt, data}` with `data` = the request as GET shows it now, stored as
 * the exact JSON body (signed and sent unchanged on every attempt). Once per request and type (+ occurrence for a
 * repeated rejection; unique `dedupe_key`, a second call returns the first event), so an event is never made twice. Sent by SendPartnerWebhookJob after the caller's
 * transaction commits; the scheduled `partners:send-webhooks` picks up anything a lost job left behind. The request's
 * `updatedAt` is moved on first, so each event's `data.updatedAt` is newer than the previous one's.
 */
class QueuePartnerWebhook
{
    /**
     * @param  string|null  $occurrence  Tells events of one type apart when the type can happen again for a request
     *                                   (a rejection after the partner sent the request again); null = once ever.
     */
    public function handle(PartnerTenantRequest $request, PartnerWebhookType $type, ?string $occurrence = null): ?PartnerWebhookEvent
    {
        if (! PartnerApi::enabled()) {
            return null; // UK only: never on an instance without the partner API
        }

        $dedupeKey = $request->id.':'.$type->value.($occurrence === null ? '' : ':'.$occurrence);
        $existing = $this->existing($dedupeKey);

        if ($existing !== null) {
            return $existing;
        }

        // Every event carries a newer `updatedAt` than anything sent before it (partners drop older ones).
        $request->touchForward();
        $id = 'evt_'.Ulid::new();
        $now = CarbonImmutable::now();
        $body = (string) json_encode([
            'id' => $id,
            'type' => $type->value,
            'createdAt' => TenantRequestView::iso($now),
            'data' => TenantRequestView::of($request),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $event = DB::transaction(fn () => PartnerWebhookEvent::query()->create([
                'id' => $id,
                'partner_id' => $request->partner_id,
                'partner_tenant_request_id' => $request->id,
                'type' => $type,
                'dedupe_key' => $dedupeKey,
                'payload' => $body,
                'next_attempt_at' => $now,
            ]));
        } catch (UniqueConstraintViolationException) {
            return $this->existing($dedupeKey) ?? throw new \LogicException('Webhook event vanished.');
        }

        SendPartnerWebhookJob::dispatch($event->id);

        return $event;
    }

    private function existing(string $dedupeKey): ?PartnerWebhookEvent
    {
        return PartnerWebhookEvent::query()->where('dedupe_key', $dedupeKey)->first();
    }
}
