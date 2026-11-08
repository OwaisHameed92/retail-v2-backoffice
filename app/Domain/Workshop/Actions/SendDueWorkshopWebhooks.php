<?php

namespace App\Domain\Workshop\Actions;

use App\Domain\Workshop\Jobs\SendWorkshopWebhookJob;
use App\Domain\Workshop\Models\WorkshopWebhookDelivery;
use App\Domain\Workshop\Support\WorkshopSwitch;
use Carbon\CarbonImmutable;

/**
 * `workshop:send-webhooks` (every minute): queues an attempt for every webhook to BikeBook that is due (not
 * delivered, not failed, next attempt passed, not claimed). Nothing while WORKSHOP_CONNECTOR is off. Returns how many.
 */
final class SendDueWorkshopWebhooks
{
    public function handle(?CarbonImmutable $now = null, int $limit = 200): int
    {
        if (! WorkshopSwitch::enabled()) {
            return 0;
        }

        $now ??= CarbonImmutable::now('UTC');
        $ids = WorkshopWebhookDelivery::withoutCompanyScope()->whereNull('delivered_at')->whereNull('failed_at')
            ->where('next_attempt_at', '<=', $now)
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<=', $now))
            ->orderBy('next_attempt_at')->limit($limit)->pluck('id');

        foreach ($ids as $id) {
            SendWorkshopWebhookJob::dispatch((string) $id);
        }

        return $ids->count();
    }
}
