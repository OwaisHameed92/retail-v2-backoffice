<?php

namespace App\Domain\Partners\Actions;

use App\Domain\Partners\Models\PartnerWebhookEvent;
use App\Domain\Partners\Support\PartnerApi;
use App\Domain\Partners\Support\WebhookSignature;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One delivery attempt of an outbox event: claim it (so two workers never send it at once), POST the stored body with
 * `X-Partner-Signature` (fresh timestamp, same body), then mark it delivered on a 2xx, else schedule the next attempt
 * with exponential backoff (config partners.webhooks) until 24 h after the event was made, then give up (`failed_at`).
 * A partner without a webhook URL or secret counts as a failed attempt, so events wait for the settings (within 24 h).
 * Returns the outcome: 'delivered', 'retry', 'failed' or 'skipped' (not due, already done, or claimed elsewhere).
 */
class DeliverPartnerWebhook
{
    public function handle(string $eventId, ?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();

        if (! PartnerApi::enabled() || ! $this->claim($eventId, $now)) {
            return 'skipped';
        }

        $event = PartnerWebhookEvent::query()->with('partner')->find($eventId);

        if ($event === null || ! $event->isPending()) {
            return 'skipped';
        }

        [$status, $error] = $this->send($event, $now);
        $event->attempts++;
        $event->last_status = $status;
        $event->locked_until = null;

        if ($status !== null && $status >= 200 && $status < 300) {
            $event->delivered_at = $now;
            $event->next_attempt_at = null;
            $event->last_error = null;
            $event->save();

            return 'delivered';
        }

        $event->last_error = mb_substr((string) $error, 0, 500);
        $next = $now->addSeconds(self::backoffSeconds($event->attempts));
        $deadline = ($event->created_at ?? $now)->addHours(max(1, (int) config('partners.webhooks.give_up_after_hours', 24)));

        if ($next->greaterThan($deadline)) {
            $event->failed_at = $now;
            $event->next_attempt_at = null;
            $event->save();
            Log::warning('Partner webhook given up after 24 hours', ['event_id' => $event->id, 'type' => $event->type->value, 'attempts' => $event->attempts]);

            return 'failed';
        }

        $event->next_attempt_at = $next;
        $event->save();

        return 'retry';
    }

    /** Seconds before the next attempt after `$attempts` failed ones: 60, 120, 240… at most an hour apart. */
    public static function backoffSeconds(int $attempts): int
    {
        $first = max(1, (int) config('partners.webhooks.first_retry_seconds', 60));
        $max = max($first, (int) config('partners.webhooks.max_retry_seconds', 3600));

        return (int) min($max, $first * (2 ** max(0, min(20, $attempts - 1))));
    }

    private function claim(string $eventId, CarbonImmutable $now): bool
    {
        return PartnerWebhookEvent::query()->whereKey($eventId)
            ->whereNull('delivered_at')->whereNull('failed_at')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<=', $now))
            ->update(['locked_until' => $now->addSeconds(max(10, (int) config('partners.webhooks.lock_seconds', 60)))]) === 1;
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    private function send(PartnerWebhookEvent $event, CarbonImmutable $now): array
    {
        $partner = $event->partner;

        if ($partner === null || ! $partner->is_active || ! $partner->sendsWebhooks()) {
            return [null, 'The partner has no webhook URL or signing secret, or is not active.'];
        }

        try {
            $response = Http::connectTimeout(max(1, (int) config('partners.webhooks.connect_timeout', 5)))
                ->timeout(max(1, (int) config('partners.webhooks.timeout', 10)))
                ->withHeaders([
                    WebhookSignature::HEADER => WebhookSignature::header((string) $partner->webhook_secret, $event->payload, $now->getTimestamp()),
                    'X-Partner-Event-Id' => $event->id,
                    'X-Partner-Event-Type' => $event->type->value,
                    'User-Agent' => 'SwitchAndSave-RetailPortal-Webhooks/1',
                ])
                ->withBody($event->payload, 'application/json')
                ->post((string) $partner->webhook_url);

            return [$response->status(), $response->successful() ? null : 'HTTP '.$response->status()];
        } catch (ConnectionException $exception) {
            return [null, 'Could not connect: '.$exception->getMessage()];
        } catch (Throwable $exception) {
            return [null, 'Send failed: '.class_basename($exception)];
        }
    }
}
