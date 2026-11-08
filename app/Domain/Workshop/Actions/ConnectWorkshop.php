<?php

namespace App\Domain\Workshop\Actions;

use App\Domain\Licensing\Enums\AddOn;
use App\Domain\Licensing\Support\BranchAddOns;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Workshop\Enums\ConnectionStatus;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Support\WorkshopSwitch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens a shop's BikeBook connection (WORKSHOP-BIKEBOOK.md §1): `pending` from the admin card's Connect, or straight
 * to `connected` when credentials are issued (the shop owner's OAuth consent, or an API key). Needs the switch on and
 * the paid add-on `workshop_integration` in the shop's licence ("Contact Switch & Save" otherwise). One connection per
 * shop: a disconnected one is opened again (its old tokens and key stay revoked). Writes `workshop.connector_status`
 * and an audit entry on every change of state.
 */
final class ConnectWorkshop
{
    public function __construct(private readonly WriteConnectorStatus $status, private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Branch $branch, ConnectionStatus $to, ?Model $actor = null): WorkshopConnection
    {
        self::ensureAllowed($branch);

        return DB::transaction(function () use ($branch, $to, $actor): WorkshopConnection {
            $connection = WorkshopConnection::withoutCompanyScope()->where('branch_id', $branch->id)
                ->where('provider', WorkshopConnection::PROVIDER)->lockForUpdate()->first();
            $before = $connection?->status;

            if ($connection !== null && ($before === $to || ($to === ConnectionStatus::Pending && $before?->carriesTraffic()))) {
                return $connection;
            }

            $connection ??= new WorkshopConnection;
            $connection->forceFill([
                'company_id' => $branch->company_id,
                'branch_id' => $branch->id,
                'provider' => WorkshopConnection::PROVIDER,
                'status' => $to,
                'default_store_id' => $branch->id,
                'connected_at' => $to->carriesTraffic() ? CarbonImmutable::now('UTC') : $connection->connected_at,
                'disconnected_at' => null,
                'last_error' => null,
                'created_by' => $connection->created_by ?? self::actorName($actor),
            ])->save();

            $this->status->handle($connection);
            $this->audit->handle($to->carriesTraffic() ? 'workshop.connected' : 'workshop.connect_started', $connection,
                $before === null ? null : ['status' => $before->value], ['status' => $to->value], ['branch' => $branch->name], $actor);

            return $connection;
        });
    }

    /**
     * @throws ValidationException
     */
    public static function ensureAllowed(Branch $branch): void
    {
        if (! WorkshopSwitch::enabled()) {
            throw ValidationException::withMessages(['workshop' => 'The BikeBook connector is not switched on.']);
        }

        if (! $branch->is_active) {
            throw ValidationException::withMessages(['workshop' => "{$branch->name} is closed. Reopen the shop first."]);
        }

        if (! BranchAddOns::has($branch, AddOn::WorkshopIntegration)) {
            throw ValidationException::withMessages(['workshop' => "Workshop integration is not in {$branch->name}'s licence. Contact Switch & Save."]);
        }
    }

    public static function actorName(?Model $actor): ?string
    {
        if ($actor === null) {
            return null;
        }

        $name = (string) ($actor->getAttribute('name') ?? $actor->getAttribute('email') ?? '');

        return mb_substr(class_basename($actor).':'.$actor->getKey().($name !== '' ? ' '.$name : ''), 0, 120);
    }
}
