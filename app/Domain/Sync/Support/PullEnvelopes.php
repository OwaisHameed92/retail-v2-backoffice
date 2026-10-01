<?php

namespace App\Domain\Sync\Support;

use App\Domain\Sync\Enums\IdKind;
use App\Domain\TillData\Registry\EntityDefinition;
use App\Domain\TillData\Sync\SettingSyncPolicy;
use App\Domain\TillData\Sync\SyncRowIds;

/**
 * One stored feed row → one pull envelope, in the till's ids (contract v1.4.1 §5, §6.1, §10.3). Ordinary rows
 * (hub-owned, relayed, drafted) go through PullPayload; the two shapes that differ are built here:
 *
 * - **Keyed rows** (§10.3): `Setting` → `{scope, scopeId, key, value, updatedAt}`, envelope `branchId` = the till's
 *   branch id of a branch setting, else ""; `RolePermission` → `{roleId, permissionKey}`, `op` `I` (granted) or `D`
 *   (taken away), never `U`. `entityId` is derived from the payload with the till's own ids (SyncRowIds), so it is
 *   the id the till itself uses. A deny-listed setting is never sent (SettingSyncPolicy; none is ever stored).
 * - **Company / Branch** (§6.1, portal edits of their details): the whole row as the till's schema has it, `op`
 *   `U`, our `updatedAt` (the till takes it only when later than its own and keeps its own `createdAt`).
 */
final class PullEnvelopes
{
    private readonly PullPayload $rows;

    public function __construct(IdTranslator $translator, private readonly string $branchId, int $since = 0)
    {
        $this->rows = new PullPayload($translator, $branchId, $since);
    }

    /**
     * @param  array<string, mixed>  $row  the stored row
     * @return array<string, mixed>|null null = never sent
     */
    public function for(EntityDefinition $def, array $row, int $version): ?array
    {
        return match (true) {
            $def->tenancy => $this->tenancy($def, $row, $version),
            $def->entity === 'Setting' => $this->setting($row, $version),
            $def->entity === 'RolePermission' => $this->rolePermission($row, $version),
            default => $this->rows->envelope($def, $row, $version),
        };
    }

    /**
     * A shop row that moved to another shop, for a shop it left (ANSWERS-2026-09-29-b A.3, ANSWERS-2026-09-30-portal
     * point 2): `D`, envelope `branchId` = the receiving shop as the till knows it (the old shop; for a row that was
     * every shop's, each other shop gets its OWN id, never "" or the new shop's), no payload, `at` = when it moved.
     * Tills 0.1.8 and 0.1.9 both soft-delete their copy. `$fromBranch` ('' = every shop) is kept for the record only.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function departure(EntityDefinition $def, array $row, int $version, string $fromBranch, string $at): array
    {
        $envelope = $this->envelope($def->entity, (string) $row['id'], 'D', $version, $row, $this->rows->till(IdKind::Branch, $this->branchId), []);

        return [...$envelope, 'at' => PullPayload::dateTime($at), 'payload' => null];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function setting(array $row, int $version): ?array
    {
        $scope = (string) $row['scope'];
        $key = (string) $row['setting_key'];

        if (SettingSyncPolicy::isLocalOnly($scope, $key)) {
            return null;
        }

        $scopeId = $this->rows->till($scope === 'company' ? IdKind::Company : IdKind::Branch, (string) $row['scope_id']);
        $payload = [
            'scope' => $scope,
            'scopeId' => $scopeId,
            'key' => $key,
            'value' => (string) $row['value'],
            'updatedAt' => PullPayload::dateTime($row['updated_at']) ?? PullPayload::dateTime(now('UTC')),
        ];
        $op = match (true) {
            $row['deleted_at'] !== null => 'D',
            PullPayload::dateTime($row['created_at']) === PullPayload::dateTime($row['updated_at']) => 'I',
            default => 'U',
        };

        return $this->envelope('Setting', SyncRowIds::setting($scope, $scopeId, $key), $op, $version, $row, $scope === 'branch' ? $scopeId : '', $payload);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function rolePermission(array $row, int $version): array
    {
        $payload = ['roleId' => (string) $row['role_id'], 'permissionKey' => (string) $row['permission_key']];
        $id = SyncRowIds::rolePermission($payload['roleId'], $payload['permissionKey']);

        return $this->envelope('RolePermission', $id, $row['deleted_at'] !== null ? 'D' : 'I', $version, $row, '', $payload);
    }

    /**
     * @param  array<string, mixed>  $row  a `companies` or `branches` row
     * @return array<string, mixed>
     */
    private function tenancy(EntityDefinition $def, array $row, int $version): array
    {
        $kind = $def->entity === 'Company' ? IdKind::Company : IdKind::Branch;
        $id = $this->rows->till($kind, (string) $row['id']);
        $companyId = $this->rows->till(IdKind::Company, (string) ($kind === IdKind::Company ? $row['id'] : $row['company_id']));
        $payload = [];

        foreach ($def->fields as $name => $field) {
            $value = PullPayload::value($field->type, $row[$field->column] ?? null);

            // The till's Company / Branch members are not nullable: an empty detail is "".
            $payload[$name] = $value ?? match ($field->type) {
                'int', 'bigint', 'money' => 0,
                'bool' => false,
                default => '',
            };
        }

        $payload = [
            ...$payload,
            'id' => $id,
            'companyId' => $companyId,
            'createdAt' => PullPayload::dateTime($row['created_at']),
            'updatedAt' => PullPayload::dateTime($row['updated_at']),
            'rowVersion' => (int) ($row['till_row_version'] ?? 1),
            'deletedAt' => null,
            'isDeleted' => false,
            'domainEvents' => [],
        ];

        return $this->envelope($def->entity, $id, 'U', $version, $row, $kind === IdKind::Branch ? $id : '', $payload, $companyId);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function envelope(string $entity, string $id, string $op, int $version, array $row, string $branchId, array $payload, ?string $companyId = null): array
    {
        $at = ($row['origin_branch_id'] ?? null) !== null ? ($row['synced_at'] ?? null) : ($row['hub_edited_at'] ?? null);

        return [
            'seq' => 0,
            'entity' => $entity,
            'entityId' => $id,
            'op' => $op,
            'version' => $version,
            'companyId' => $companyId ?? $this->rows->till(IdKind::Company, (string) $row['company_id']),
            'branchId' => $branchId,
            'registerId' => '',
            'at' => PullPayload::dateTime($at) ?? PullPayload::dateTime($row['updated_at']) ?? PullPayload::dateTime(now('UTC')),
            'payload' => $payload,
            'key' => "{$entity}:{$id}:{$version}",
        ];
    }
}
