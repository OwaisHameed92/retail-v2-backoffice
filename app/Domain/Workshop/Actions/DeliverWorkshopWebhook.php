<?php

namespace App\Domain\Workshop\Actions;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Scopes\CompanyScope;
use App\Domain\Workshop\Enums\ConnectionStatus;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Models\WorkshopWebhookDelivery;
use App\Domain\Workshop\Queries\ConnectorInventory;
use App\Domain\Workshop\Support\WebhookSignature;
use App\Domain\Workshop\Support\WorkshopSwitch;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * One attempt of a webhook to BikeBook (WORKSHOP-BIKEBOOK.md §3.8): claim it (two workers never send it at once),
 * POST the exact stored body with `X-Pos-Signature` = Base64(HMAC-SHA256(secret, body)) and `X-Pos-Event-Id` = the
 * delivery id (BikeBook de-duplicates by it: at least once). A 2xx is delivered (a `failed` connection is `connected`
 * again); anything else waits 1 min, 5 min, 15 min, 1 h, 4 h, then the delivery is failed, the connection `failed`
 * with the reason, and staff can Replay it. An `inventory.updated` gets its body (the stock right now) on its first
 * attempt. Returns delivered, retry, failed or skipped.
 */
class DeliverWorkshopWebhook
{
    public function __construct(
        private readonly ConnectorInventory $inventory,
        private readonly CurrentCompany $tenancy,
        private readonly WriteConnectorStatus $status,
    ) {}

    public function handle(string $deliveryId, ?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now('UTC');

        if (! WorkshopSwitch::enabled() || ! $this->claim($deliveryId, $now)) {
            return 'skipped';
        }

        $delivery = WorkshopWebhookDelivery::withoutCompanyScope()->with(['connection' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class)])->find($deliveryId);
        $connection = $delivery?->connection;

        if ($delivery === null || ! $delivery->isPending() || $connection === null) {
            return 'skipped';
        }

        if ($delivery->payload === null) {
            $delivery->payload = $this->inventoryBody($connection, $delivery->subject_ids ?? []);
        }

        [$status, $error] = $this->send($connection, $delivery);
        $delivery->attempts++;
        $delivery->last_status = $status;
        $delivery->locked_until = null;

        if ($status !== null && $status >= 200 && $status < 300) {
            $delivery->forceFill(['delivered_at' => $now, 'next_attempt_at' => null, 'last_error' => null])->save();
            $this->recovered($connection);

            return 'delivered';
        }

        $delivery->last_error = mb_substr((string) $error, 0, 500);
        $waits = array_values((array) config('workshop.webhooks.retry_minutes', [1, 5, 15, 60, 240]));
        $wait = $waits[$delivery->attempts - 1] ?? null;

        if ($wait === null) {
            $delivery->forceFill(['failed_at' => $now, 'next_attempt_at' => null])->save();
            $this->failed($connection, $delivery);

            return 'failed';
        }

        $delivery->forceFill(['next_attempt_at' => $now->addMinutes((int) $wait)])->save();

        return 'retry';
    }

    private function claim(string $id, CarbonImmutable $now): bool
    {
        return WorkshopWebhookDelivery::withoutCompanyScope()->whereKey($id)->whereNull('delivered_at')->whereNull('failed_at')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<=', $now))
            ->update(['locked_until' => $now->addSeconds(max(10, (int) config('workshop.webhooks.lock_seconds', 60)))]) === 1;
    }

    /**
     * @return array{0: int|null, 1: string|null}
     */
    private function send(WorkshopConnection $connection, WorkshopWebhookDelivery $delivery): array
    {
        if (! $connection->status->carriesTraffic() || ! $connection->hasWebhook() || ! $connection->webhook_enabled) {
            return [null, 'BikeBook has no enabled webhook on this connection.'];
        }

        try {
            $body = (string) $delivery->payload;
            $response = Http::connectTimeout(max(1, (int) config('workshop.webhooks.connect_timeout', 5)))
                ->timeout(max(1, (int) config('workshop.webhooks.timeout', 10)))
                ->withHeaders([
                    WebhookSignature::HEADER => WebhookSignature::sign((string) $connection->webhook_secret, $body),
                    'X-Pos-Event-Id' => $delivery->id,
                    'X-Pos-Event' => $delivery->event->value,
                    'User-Agent' => 'SwitchAndSave-SSPOS-Connector/1',
                ])
                ->withBody($body, 'application/json')
                ->post((string) $connection->webhook_url);

            return [$response->status(), $response->successful() ? null : 'HTTP '.$response->status()];
        } catch (ConnectionException) {
            return [null, 'Could not connect to BikeBook.'];
        } catch (Throwable $e) {
            return [null, 'Send failed: '.class_basename($e)];
        }
    }

    /**
     * @param  list<string>  $variantIds
     */
    private function inventoryBody(WorkshopConnection $connection, array $variantIds): string
    {
        $company = Company::query()->findOrFail($connection->company_id);
        $rows = $this->tenancy->runAs($company, fn () => $this->inventory->forVariants($connection, $variantIds));

        return (string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function recovered(WorkshopConnection $connection): void
    {
        if ($connection->status === ConnectionStatus::Failed) {
            $connection->forceFill(['status' => ConnectionStatus::Connected, 'last_error' => null])->save();
            $this->status->handle($connection);
        }
    }

    private function failed(WorkshopConnection $connection, WorkshopWebhookDelivery $delivery): void
    {
        $reason = "Webhook {$delivery->event->value} not delivered after {$delivery->attempts} attempts: {$delivery->last_error}";
        $connection->forceFill(['last_error' => mb_substr($reason, 0, 1000)]);

        if ($connection->status === ConnectionStatus::Connected) {
            $connection->status = ConnectionStatus::Failed;
        }

        $connection->save();
        $this->status->handle($connection);
    }
}
