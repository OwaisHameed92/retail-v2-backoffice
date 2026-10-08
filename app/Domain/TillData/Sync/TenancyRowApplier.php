<?php

namespace App\Domain\TillData\Sync;

use App\Domain\Tenancy\Enums\Nation;
use App\Domain\TillData\Sync\Data\MappedChange;
use App\Domain\TillData\Sync\Data\Rejection;
use App\Domain\TillData\Sync\Enums\ChangeOutcome;
use App\Domain\TillData\Sync\Enums\ConflictKind;
use Illuminate\Support\Facades\DB;

/**
 * The till's Company, Branch and Register rows land on module 1.2's tables. Only the till-owned fields
 * (definitions.php `tenancy.*.tillFields`) are written; ids, codes, status, is_active and is_main_till stay
 * the portal's. A till may only send its own company, its own branch and that branch's tills. A delete is
 * never applied (recorded as a conflict). Versions live in till_row_version, so older versions are stale.
 */
final class TenancyRowApplier
{
    public function __construct(private readonly SyncContext $context, private readonly ConflictRecorder $conflicts) {}

    public function apply(MappedChange $mapped): ChangeOutcome|Rejection
    {
        $change = $mapped->change;
        $def = $mapped->definition;

        $rejection = match ($change->entity) {
            'Company' => $change->entityId === $this->context->companyId ? null : Rejection::for($change, 'sync.wrong_company', 'A till may only send its own company.'),
            'Branch' => $change->entityId === $this->context->branchId ? null : Rejection::for($change, 'sync.wrong_branch', 'A till may only send its own branch.'),
            default => $this->context->ownsRegister($change->entityId) ? null : Rejection::for($change, 'sync.unknown_register', 'The till is not one of the sending branch\'s tills.'),
        };

        if ($rejection !== null) {
            return $rejection;
        }

        $current = DB::table($def->table)->where('id', $change->entityId)->lockForUpdate()->first(['id', 'till_row_version']);

        if ($current === null) {
            return Rejection::for($change, 'entity.not_found', "Unknown {$change->entity} {$change->entityId}.");
        }

        if ($current->till_row_version !== null && $change->version <= (int) $current->till_row_version) {
            return ChangeOutcome::Stale;
        }

        $values = [
            'till_row_version' => $change->version,
            'till_synced_at' => $this->context->now,
            'till_sync_seq' => $change->seq > 0 ? $change->seq : null,
        ];

        if ($change->isDelete()) {
            $this->conflicts->add($mapped, ConflictKind::TenancyDelete, $current->till_row_version === null ? null : (int) $current->till_row_version, "The till deleted its {$change->entity}. It is owned by the portal and was not deleted.");
        } elseif ($mapped->row !== null) {
            $values = [...$values, ...$this->tillValues($mapped), 'till_updated_at' => $mapped->row['updated_at'], 'till_extra' => $mapped->row['extra'], 'updated_at' => $this->context->now];
        }

        DB::table($def->table)->where('id', $change->entityId)->update($values);

        return ChangeOutcome::Applied;
    }

    /**
     * @return array<string, mixed> portal column => value
     */
    private function tillValues(MappedChange $mapped): array
    {
        $values = [];
        $row = (array) $mapped->row;

        foreach ($mapped->definition->tillFields as $field => $column) {
            $value = $row[$mapped->definition->fields[$field]->column] ?? null;

            // The portal needs a display name, and only keeps the nations its profile uses (Nation::fromTill).
            if (($field === 'name' && ($value === null || $value === '')) || ($field === 'nation' && Nation::fromTill($value) === null)) {
                continue;
            }

            $values[$column] = $value === '' && $field !== 'licensedHoursJson' ? null : $value;
        }

        return $values;
    }
}
