<?php

namespace App\Domain\Workshop\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Workshop\Enums\ConnectionStatus;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Models\WorkshopToken;
use App\Domain\Workshop\Support\ConnectorSecret;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * The API key fallback (WORKSHOP-BIKEBOOK.md §1.2): "Create BikeBook key" for one shop. The key is returned once
 * (the caller shows it with `Cache-Control: no-store`) and stored as an HMAC + last 4; a new key replaces the old one
 * at once. Creating a key connects the shop. `revoke()` removes the key; when no OAuth token is left either, the
 * connection goes back to `pending`. Never logged or audited with the key.
 */
final class IssueWorkshopApiKey
{
    public function __construct(
        private readonly ConnectWorkshop $connect,
        private readonly WriteConnectorStatus $status,
        private readonly RecordAudit $audit,
    ) {}

    public function handle(Branch $branch, ?Model $actor = null): string
    {
        ConnectWorkshop::ensureAllowed($branch);

        return DB::transaction(function () use ($branch, $actor): string {
            $connection = $this->connect->handle($branch, ConnectionStatus::Connected, $actor);
            $key = ConnectorSecret::newApiKey();
            $replaced = $connection->api_key_last4 !== null;
            $this->store($connection, $key);
            $this->audit->handle($replaced ? 'workshop.api_key_replaced' : 'workshop.api_key_created', $connection, null, ['last4' => $connection->api_key_last4], ['branch' => $branch->name], $actor);

            return $key;
        });
    }

    public function revoke(WorkshopConnection $connection, ?Model $actor = null): WorkshopConnection
    {
        return DB::transaction(function () use ($connection, $actor): WorkshopConnection {
            $connection = WorkshopConnection::withoutCompanyScope()->whereKey($connection->id)->lockForUpdate()->firstOrFail();

            if ($connection->api_key_hash === null) {
                return $connection;
            }

            $last4 = $connection->api_key_last4;
            $connection->forceFill(['api_key_hash' => null, 'api_key_last4' => null, 'api_key_created_at' => null]);
            $oauthLeft = WorkshopToken::withoutCompanyScope()->where('connection_id', $connection->id)->where('kind', 'refresh')
                ->whereNull('revoked_at')->where('expires_at', '>', CarbonImmutable::now('UTC'))->exists();

            if (! $oauthLeft && $connection->status->carriesTraffic()) {
                $connection->status = ConnectionStatus::Pending;
            }

            $connection->save();
            $this->status->handle($connection);
            $this->audit->handle('workshop.api_key_revoked', $connection, ['last4' => $last4], null, [], $actor);

            return $connection;
        });
    }

    private function store(WorkshopConnection $connection, #[SensitiveParameter] string $key): void
    {
        $connection->forceFill([
            'api_key_hash' => ConnectorSecret::hashKey($key),
            'api_key_last4' => ConnectorSecret::last4($key),
            'api_key_created_at' => CarbonImmutable::now('UTC'),
        ])->save();
    }
}
