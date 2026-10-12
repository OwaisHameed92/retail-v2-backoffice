<?php

namespace App\Console\Commands;

use App\Domain\Partners\Actions\DeliverPartnerWebhook;
use App\Domain\Partners\Actions\SendDueWebhooks;
use App\Domain\Partners\Models\PartnerWebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Partner onboarding API: the webhook outbox's retry loop. Scheduled every minute: queues a delivery attempt for every
 * due event (first attempts are queued when the event is made; this picks up retries and lost jobs). `--now` sends
 * them in this process instead.
 */
class PartnersSendWebhooksCommand extends Command
{
    protected $signature = 'partners:send-webhooks {--now : Send in this process instead of queueing}';

    protected $description = 'Queue (or send) the partner webhooks that are due';

    public function handle(SendDueWebhooks $due, DeliverPartnerWebhook $deliver): int
    {
        if (! $this->option('now')) {
            $this->info('Queued '.$due->handle().' webhooks.');

            return self::SUCCESS;
        }

        $now = CarbonImmutable::now();
        $results = [];

        $ids = PartnerWebhookEvent::query()->whereNull('delivered_at')->whereNull('failed_at')
            ->where('next_attempt_at', '<=', $now)->orderBy('next_attempt_at')->limit(200)->pluck('id');

        foreach ($ids as $id) {
            $outcome = $deliver->handle((string) $id, $now);
            $results[$outcome] = ($results[$outcome] ?? 0) + 1;
        }

        $this->info($results === [] ? 'Nothing due.' : implode(', ', array_map(fn ($outcome, $count) => "{$count} {$outcome}", array_keys($results), $results)).'.');

        return self::SUCCESS;
    }
}
