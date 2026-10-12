<?php

namespace App\Domain\Partners\Actions;

use App\Domain\Partners\Jobs\SendPartnerWebhookJob;
use App\Domain\Partners\Models\PartnerWebhookEvent;
use App\Domain\Partners\Support\PartnerApi;
use Carbon\CarbonImmutable;

/**
 * `partners:send-webhooks` (every minute): queues a delivery attempt for every outbox event that is due (not
 * delivered, not given up, next attempt passed, not claimed by a worker). Returns how many were queued.
 */
class SendDueWebhooks
{
    public function handle(?CarbonImmutable $now = null, int $limit = 200): int
    {
        $now ??= CarbonImmutable::now();

        if (! PartnerApi::enabled()) {
            return 0;
        }

        $ids = PartnerWebhookEvent::query()
            ->whereNull('delivered_at')->whereNull('failed_at')
            ->where('next_attempt_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<=', $now))
            ->orderBy('next_attempt_at')->limit($limit)->pluck('id');

        foreach ($ids as $id) {
            SendPartnerWebhookJob::dispatch((string) $id);
        }

        return $ids->count();
    }
}
