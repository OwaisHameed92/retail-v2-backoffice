<?php

namespace App\Domain\Workshop\Support;

use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Support\PullPayload;
use App\Domain\Tenancy\Scopes\CompanyScope;
use App\Domain\TillData\Sync\HubVersions;
use App\Domain\Workshop\Enums\ConnectionStatus;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Models\WorkshopOrder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * `WorkshopOrder` rows in a shop's pull (WORKSHOP-BIKEBOOK.md §5; contract proposal workshop-order). `samples/
 * ownership.json` still lists WorkshopOrder as `local`, so it is kept out of every pull unless the switch is on, the
 * till is new enough (WorkshopSwitch) AND the shop's BikeBook connection carries traffic (connected, or connected
 * with a failing webhook). PullChanges asks `serves()` first; when it says no nothing here runs and the pull is
 * byte-identical.
 *
 * Hub-owned and sent to that shop only (envelope `branchId` = the shop). Every version of an order is one envelope
 * (`I` for version 1, `U` after); the same version again is a no-op on the till. Never echoed: the till never pushes
 * WorkshopOrder (it pushes the CustomerOrder it made, same id).
 */
final class WorkshopOrderFeed
{
    public const LABEL = '#WorkshopOrder';

    public const ENTITY = 'WorkshopOrder';

    public function __construct(private readonly HubVersions $versions) {}

    public static function owns(string $entity): bool
    {
        return $entity === self::LABEL;
    }

    public function serves(string $companyId, string $branchId, string $appVersion): bool
    {
        return WorkshopSwitch::servesTill($appVersion)
            && WorkshopConnection::withoutCompanyScope()->where('company_id', $companyId)->where('branch_id', $branchId)
                ->whereIn('status', [ConnectionStatus::Connected->value, ConnectionStatus::Failed->value])->exists();
    }

    /** Stamps orders whose after-commit stamp did not run. */
    public function prepare(string $companyId, string $branchId): void
    {
        $waiting = WorkshopOrder::withoutCompanyScope()->where('company_id', $companyId)->where('branch_id', $branchId)
            ->whereNull('hub_version')->orderBy('updated_at')->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->versions->stampTables($companyId, [['workshop_orders', $waiting]]);
    }

    /**
     * @return list<Builder> union members with PullFeed's columns
     */
    public function sources(string $companyId, string $branchId, int $since): array
    {
        return [
            DB::table('workshop_orders')
                ->selectRaw('? as entity, id, hub_version as version, NULL as departed_from, NULL as departed_at', [self::LABEL])
                ->where('company_id', $companyId)->where('branch_id', $branchId)->where('hub_version', '>', $since)
                ->whereIn('connection_id', DB::table('workshop_connections')->select('id')->where('company_id', $companyId)->where('branch_id', $branchId)
                    ->whereIn('status', [ConnectionStatus::Connected->value, ConnectionStatus::Failed->value])),
        ];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: mixed}>  $page
     * @return array<string, array<string, mixed>> "label:id" => envelope
     */
    public function envelopes(PullPayload $ids, string $companyId, string $branchId, array $page): array
    {
        $wanted = [];

        foreach ($page as [$entity, $id, $version]) {
            if ($entity === self::LABEL) {
                $wanted[$id] = $version;
            }
        }

        if ($wanted === []) {
            return [];
        }

        $orders = WorkshopOrder::withoutCompanyScope()->with(['lines' => fn ($q) => $q->withoutGlobalScope(CompanyScope::class)])->where('company_id', $companyId)->where('branch_id', $branchId)
            ->whereIn('id', array_keys($wanted))->get()->keyBy('id');
        $envelopes = [];
        $sent = [];

        foreach ($wanted as $id => $version) {
            $order = $orders->get($id);

            // Changed since the page was read: its newer version comes in a later pull.
            if (! $order instanceof WorkshopOrder || $order->hub_version !== $version) {
                continue;
            }

            $payload = WorkshopOrderPayload::for($order, $ids);
            $envelopes[self::LABEL.':'.$id] = [
                'seq' => 0,
                'entity' => self::ENTITY,
                'entityId' => $order->id,
                'op' => $order->version === 1 ? 'I' : 'U',
                'version' => $version,
                'companyId' => $ids->till(IdKind::Company, $companyId),
                'branchId' => $ids->till(IdKind::Branch, $branchId),
                'registerId' => '',
                'at' => $payload['updatedAt'] ?? PullPayload::dateTime(now('UTC')),
                'payload' => $payload,
                'key' => self::ENTITY.":{$order->id}:{$order->version}",
            ];

            if ($order->pulled_at === null) {
                $sent[] = $order->id;
            }
        }

        if ($sent !== []) {
            DB::table('workshop_orders')->whereIn('id', $sent)->whereNull('pulled_at')->update(['pulled_at' => now('UTC')->format('Y-m-d H:i:s')]);
        }

        return $envelopes;
    }
}
