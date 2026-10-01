<?php

namespace App\Domain\Ai\Tools\Portal;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Support\Portal\ShopPin;
use App\Domain\Shared\Rules\ValidUlid;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Register;
use App\Domain\TillHealth\Queries\CompanyHealth;
use Carbon\CarbonImmutable;

/**
 * Read: are the tills online and syncing, now (module 2.7 CompanyHealth, worked out from the source rows for this
 * business only, the tenant view: no install ids).
 */
final class GetTillHealth extends PortalReadTool
{
    public function name(): string
    {
        return 'get_till_health';
    }

    public function description(): string
    {
        return 'Till health now: for each shop and till, whether it is online, when it was last seen and last synced, '
            .'rows waiting to sync, the app version and any problems (offline, not syncing, out of date, clock wrong).';
    }

    public function inputSchema(): array
    {
        return self::object(['shop_id' => ShopPin::schema()]);
    }

    public function rules(): array
    {
        return ['shop_id' => ['nullable', 'string', new ValidUlid]];
    }

    public function requiredAbility(): Ability
    {
        return Ability::ShopsView;
    }

    public function handle(array $input, AiContext $context): array
    {
        $shop = ShopPin::resolve($input['shop_id'] ?? null);
        $health = CompanyHealth::for(app(CurrentCompany::class)->require()->getKey(), CarbonImmutable::now(), admin: false);
        $keep = fn (array $row) => $shop->id === null || ($row['branchId'] ?? null) === $shop->id;

        $branches = Branch::query()->withTrashed()->pluck('name', 'id');
        $tills = Register::query()->withTrashed()->get(['id', 'code', 'name'])->keyBy('id');

        $this->links->add('Shops and tills', '/app/shops', [], $shop);

        return [
            ...$shop->toArray(),
            'checkedAt' => CarbonImmutable::now()->toIso8601String(),
            'shops' => array_values(array_map(fn (array $b) => [
                'shop' => $branches[$b['branchId']] ?? 'Unknown shop',
                'lastContactAt' => $b['lastContactAt'],
                'lastSyncAt' => $b['lastSyncAt'],
                'tills' => $b['tills'],
                'tillsOnline' => $b['tillsOnline'],
                'tillsOffline' => $b['tillsOffline'],
                'syncError' => $b['lastError']['message'] ?? null,
            ], array_filter($health['branches'], $keep))),
            'tills' => array_values(array_map(fn (array $t) => [
                'till' => trim(($tills[$t['registerId']]->code ?? '').' – '.($tills[$t['registerId']]->name ?? ''), ' –') ?: 'Unknown till',
                'shop' => $branches[$t['branchId']] ?? 'Unknown shop',
                'state' => $t['stateLabel'] ?? null,
                'sync' => $t['syncStateLabel'] ?? null,
                'lastSeenAt' => $t['lastSeenAt'],
                'lastPushAt' => $t['lastPushAt'],
                'appVersion' => $t['appVersion'],
                'rowsWaitingToSync' => $t['pendingSyncRows'],
                'problems' => array_column($t['problems'] ?? [], 'label'),
            ], array_filter($health['tills'], $keep))),
        ];
    }
}
