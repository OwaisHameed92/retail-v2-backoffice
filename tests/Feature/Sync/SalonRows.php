<?php

namespace Tests\Feature\Sync;

use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Sync\SyncRowIds;
use Tests\Feature\TillData\TillFixtures;

/**
 * Salon pack rows (W2, docs/contracts/salon-barber-2026-11-08) as a Leeds till pushes them: every required member of
 * the salon schemas (built on PullTestHelpers::payload), the shop's own branchId (and till for tips), and the pack's
 * online request samples.
 */
final class SalonRows
{
    /** Salon rows that name the till that took them as well as the shop. */
    private const REGISTER = ['SalonTip', 'SalonTipPayout'];

    /** @return list<string> every stored Salon* entity (the 40 of the pack; SalonClientMedical is local) */
    public static function entities(): array
    {
        return array_values(array_filter(EntityRegistry::names(), fn (string $e) => str_starts_with($e, 'Salon')));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function payload(string $entity, string $id, array $overrides = [], string $branch = TillFixtures::LEEDS): array
    {
        $def = EntityRegistry::get($entity);
        $scope = $def->hasScopeColumn('branch_id') ? ['branchId' => $branch] : [];

        if (in_array($entity, self::REGISTER, true)) {
            $scope['registerId'] = $branch === TillFixtures::LEEDS ? TillFixtures::TILL_1 : TillFixtures::BRADFORD_TILL;
        }

        return PullTestHelpers::payload($entity, $id, [...$scope, 'isDeleted' => false, 'domainEvents' => [], ...$overrides]);
    }

    /**
     * @param  array<string, mixed>  $overrides  payload members
     * @param  array<string, mixed>  $envelope  envelope members
     * @return array<string, mixed>
     */
    public static function envelope(string $entity, string $id, int $seq, array $overrides = [], array $envelope = [], string $branch = TillFixtures::LEEDS): array
    {
        return [...TillFixtures::envelope($entity, self::payload($entity, $id, $overrides, $branch), $seq), ...$envelope];
    }

    /** A ULID-shaped id for test row $n of an entity. */
    public static function id(string $entity, int $n = 1): string
    {
        return '01K9SA'.strtoupper(substr(md5($entity), 0, 12)).str_pad((string) $n, 8, '0', STR_PAD_LEFT);
    }

    /**
     * One of the pack's online request samples (salon/samples/online-request.*.json): a SalonOnlineBooking payload.
     *
     * @return array<string, mixed>
     */
    public static function onlineRequest(string $name): array
    {
        return json_decode((string) file_get_contents(base_path(TillFixtures::SALON."/salon/samples/online-request.{$name}.json")), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * A Setting row (keyed, §10.3) of a shop, as its till pushes it.
     *
     * @return array<string, mixed>
     */
    public static function setting(string $key, string $value, int $seq, string $scope = 'branch', string $branch = TillFixtures::LEEDS): array
    {
        $scopeId = $scope === 'company' ? TillFixtures::COMPANY : $branch;
        $payload = ['scope' => $scope, 'scopeId' => $scopeId, 'key' => $key, 'value' => $value, 'updatedAt' => '2026-11-08T09:00:00Z'];

        return [
            'seq' => $seq, 'entity' => 'Setting', 'entityId' => SyncRowIds::setting($scope, $scopeId, $key),
            'op' => 'I', 'version' => $seq, 'companyId' => TillFixtures::COMPANY, 'branchId' => $branch, 'registerId' => '',
            'at' => $payload['updatedAt'], 'payload' => $payload, 'key' => "Setting:{$key}:{$seq}",
        ];
    }

    /**
     * A RolePermission grant (keyed, §10.3).
     *
     * @return array<string, mixed>
     */
    public static function right(string $roleId, string $key, int $seq, string $branch = TillFixtures::LEEDS): array
    {
        return [
            'seq' => $seq, 'entity' => 'RolePermission', 'entityId' => SyncRowIds::rolePermission($roleId, $key), 'op' => 'I', 'version' => $seq,
            'companyId' => TillFixtures::COMPANY, 'branchId' => $branch, 'registerId' => '', 'at' => '2026-11-08T09:00:00Z',
            'payload' => ['roleId' => $roleId, 'permissionKey' => $key], 'key' => "RolePermission:{$seq}",
        ];
    }
}
