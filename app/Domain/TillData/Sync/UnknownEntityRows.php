<?php

namespace App\Domain\TillData\Sync;

use App\Domain\Shared\Support\Redactor;
use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Sync\Data\SyncChange;
use App\Domain\TillData\Sync\Enums\ChangeOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rows of an entity the portal does not know yet (a newer till, contract v1.4.1 §18.8, §21.1): accepted and kept raw
 * in `till_unknown_rows` (payload JSON as sent, secret-looking members redacted, §21.8), one row per (company, entity, id) at its highest version. Recorded in
 * the push ledger like any other change, so a retry is a duplicate and changes nothing.
 */
final class UnknownEntityRows
{
    public function __construct(private readonly ChangeLedger $ledger) {}

    public static function matches(SyncChange $change): bool
    {
        return ! EntityRegistry::has($change->entity) && ! EntityRegistry::isLocal($change->entity);
    }

    /**
     * @param  list<SyncChange>  $changes  seq order
     * @return array<int, ChangeOutcome> change index => outcome
     */
    public function store(array $changes, SyncContext $context): array
    {
        if ($changes === []) {
            return [];
        }

        $outcomes = DB::transaction(fn () => $this->storeInTransaction($changes, $context));

        Log::warning('Till sync stored rows of entities the portal does not know (kept raw in till_unknown_rows).', [
            'company_id' => $context->companyId,
            'branch_id' => $context->branchId,
            'entities' => array_slice(array_count_values(array_map(fn (SyncChange $c) => $c->entity, $changes)), 0, 20, true),
        ]);

        return $outcomes;
    }

    /**
     * @param  list<SyncChange>  $changes
     * @return array<int, ChangeOutcome>
     */
    private function storeInTransaction(array $changes, SyncContext $context): array
    {
        $applied = $this->ledger->applied($context, array_map(fn (SyncChange $c) => $c->seq, $changes));
        $versions = $this->storedVersions($changes, $context);
        $outcomes = [];
        $accepted = [];

        foreach ($changes as $change) {
            if ($change->seq > 0 && isset($applied[$change->seq])) {
                $outcomes[$change->index] = ChangeOutcome::Duplicate;

                continue;
            }

            $row = $change->entity."\0".$change->entityId;
            $outcome = isset($versions[$row]) && $versions[$row] >= $change->version ? ChangeOutcome::Stale : ChangeOutcome::Applied;

            if ($outcome === ChangeOutcome::Applied) {
                $this->write($change, $context, isset($versions[$row]));
                $versions[$row] = $change->version;
            }

            $outcomes[$change->index] = $outcome;
            $accepted[] = ['change' => $change, 'outcome' => $outcome];
        }

        $this->ledger->record($context, $accepted);

        return $outcomes;
    }

    private function write(SyncChange $change, SyncContext $context, bool $exists): void
    {
        $values = [
            'branch_id' => $change->branchId !== '' ? $change->branchId : $context->branchId,
            'register_id' => $change->registerId !== '' ? $change->registerId : null,
            'op' => $change->op,
            'version' => $change->version,
            'seq' => $change->seq,
            'at' => $change->at,
            // §21.8: kept raw except secret-looking members (a password, key, token or secret), as `extra` is.
            'payload' => $change->payload === null ? null : json_encode(Redactor::redact($change->payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'received_at' => $context->now,
        ];
        $key = ['company_id' => $context->companyId, 'entity' => $change->entity, 'entity_id' => $change->entityId];

        $exists
            ? DB::table('till_unknown_rows')->where($key)->update($values)
            : DB::table('till_unknown_rows')->insert($key + $values);
    }

    /**
     * @param  list<SyncChange>  $changes
     * @return array<string, int> "entity\0id" => stored version
     */
    private function storedVersions(array $changes, SyncContext $context): array
    {
        $versions = [];

        foreach (collect($changes)->groupBy('entity') as $entity => $group) {
            foreach ($group->pluck('entityId')->unique()->chunk(500) as $ids) {
                DB::table('till_unknown_rows')
                    ->where('company_id', $context->companyId)
                    ->where('entity', $entity)
                    ->whereIn('entity_id', $ids->values()->all())
                    ->pluck('version', 'entity_id')
                    ->each(function (mixed $version, string $id) use (&$versions, $entity): void {
                        $versions[$entity."\0".$id] = (int) $version;
                    });
            }
        }

        return $versions;
    }
}
