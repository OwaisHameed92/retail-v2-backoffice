<?php

namespace App\Domain\Workshop\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Workshop\Enums\ConnectionStatus;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Models\WorkshopToken;
use App\Domain\Workshop\Models\WorkshopWebhookDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Disconnects BikeBook from a shop (WORKSHOP-BIKEBOOK.md §1.2 "Revoke", joint test 10): every OAuth code and token
 * and the API key revoked at once (BikeBook's next call gets 401), the webhook stopped (deliveries not yet sent are
 * marked failed), `workshop.connector_status` = disconnected so the till hides its connector screens. Orders already
 * on the till stay there and stay readable; nothing new goes down.
 */
final class DisconnectWorkshop
{
    public function __construct(private readonly WriteConnectorStatus $status, private readonly RecordAudit $audit) {}

    public function handle(WorkshopConnection $connection, ?Model $actor = null, string $reason = 'Disconnected on the portal.'): WorkshopConnection
    {
        return DB::transaction(function () use ($connection, $actor, $reason): WorkshopConnection {
            $connection = WorkshopConnection::withoutCompanyScope()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $before = $connection->status;
            $now = CarbonImmutable::now('UTC');

            WorkshopToken::withoutCompanyScope()->where('connection_id', $connection->id)->whereNull('revoked_at')->update(['revoked_at' => $now]);
            WorkshopWebhookDelivery::withoutCompanyScope()->where('connection_id', $connection->id)->whereNull('delivered_at')->whereNull('failed_at')
                ->update(['failed_at' => $now, 'next_attempt_at' => null, 'last_error' => 'Not sent: BikeBook was disconnected.']);

            if ($before === ConnectionStatus::Disconnected && $connection->api_key_hash === null) {
                return $connection;
            }

            $connection->forceFill([
                'status' => ConnectionStatus::Disconnected,
                'disconnected_at' => $now,
                'api_key_hash' => null,
                'api_key_last4' => null,
                'api_key_created_at' => null,
                'webhook_enabled' => false,
                'last_error' => null,
            ])->save();

            $this->status->handle($connection);
            $this->audit->handle('workshop.disconnected', $connection, ['status' => $before->value], ['status' => ConnectionStatus::Disconnected->value], ['reason' => $reason], $actor);

            return $connection;
        });
    }
}
