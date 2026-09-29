<?php

namespace App\Domain\TillData\Sync;

use App\Domain\TillData\Registry\EntityDefinition;
use App\Domain\TillData\Sync\Data\Rejection;
use App\Domain\TillData\Sync\Data\SyncChange;

/**
 * Keyed rows (contract v1.4 §10.3): `Setting` and `RolePermission` have no ULID, companyId or row version of their
 * own. The row is identified by its payload (`keyedBy` in definitions.php) and stored under the id derived from it
 * (SyncRowIds) with our own ids, whatever id the till sent. `version` is the pushing branch's seq.
 *
 * A setting's scope must be the pushing company (`company`) or branch (`branch`); a blank `scopeId` means the
 * till's own. Register-scope and deny-listed settings never get here (ApplySyncChanges skips them).
 */
final class KeyedRows
{
    public static function identify(SyncChange $change, EntityDefinition $def, SyncContext $context): SyncChange|Rejection
    {
        $payload = (array) $change->payload;

        foreach ((array) $def->keyedBy as $field) {
            if (! is_string($payload[$field] ?? null)) {
                return Rejection::for($change, 'payload.invalid', "Invalid {$def->entity}: {$field} must be a string.");
            }
        }

        if ($def->entity === 'Setting') {
            $scopeId = $payload['scopeId'];

            [$expected, $code] = match ($payload['scope']) {
                'company' => [$context->companyId, 'sync.wrong_company'],
                'branch' => [$context->branchId, 'sync.wrong_branch'],
                default => [null, 'payload.invalid'],
            };

            if ($expected === null) {
                return Rejection::for($change, $code, 'Invalid Setting: scope must be company or branch.');
            }

            $payload['scopeId'] = $scopeId === '' ? $expected : $scopeId;

            if ($payload['scopeId'] !== $expected) {
                return Rejection::for($change, $code, "The setting belongs to another {$payload['scope']} than the one sending it.");
            }
        }

        $parts = array_map(fn (string $field) => $payload[$field], (array) $def->keyedBy);

        return $change->withEntityId(SyncRowIds::of($def->entity.'|'.implode('|', $parts)), $payload);
    }
}
