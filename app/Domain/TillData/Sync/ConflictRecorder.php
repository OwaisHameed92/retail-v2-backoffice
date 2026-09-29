<?php

namespace App\Domain\TillData\Sync;

use App\Domain\Shared\Support\Ulid;
use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Sync\Data\MappedChange;
use App\Domain\TillData\Sync\Enums\ConflictKind;
use Illuminate\Support\Facades\DB;

/**
 * Collects sync_conflicts rows during a chunk and inserts them with it (same transaction). The incoming payload is
 * kept as sent so a person can apply the till's version later (ResolveSyncConflict), except secret members (licence
 * keys), and dropped members (a user's remote approval secret), which are never stored. A hub-owned row kept against
 * a till's change is queued for the pull again, so the portal's winning row reaches that shop (§19.3).
 */
final class ConflictRecorder
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];

    public function __construct(private readonly SyncContext $context) {}

    public function add(MappedChange $mapped, ConflictKind $kind, ?int $localVersion, string $detail): void
    {
        $change = $mapped->change;
        $payload = $change->payload;

        if (is_array($payload)) {
            foreach ($mapped->definition->fields as $name => $field) {
                if ($field->type === 'secret') {
                    unset($payload[$name]);
                }
            }

            foreach ($mapped->definition->dropped as $name) {
                unset($payload[$name]);
            }
        }

        $this->rows[] = [
            'id' => Ulid::new(),
            'company_id' => $this->context->companyId,
            'branch_id' => $this->context->branchId,
            'entity' => $change->entity,
            'entity_id' => $change->entityId,
            'kind' => $kind->value,
            'local_version' => $localVersion,
            'incoming_version' => $change->version,
            'incoming_seq' => $change->seq > 0 ? $change->seq : null,
            'incoming_at' => $change->at,
            'incoming_payload' => $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
            'detail' => mb_substr($detail, 0, 1000),
            'status' => 'open',
            'created_at' => $this->context->now,
            'updated_at' => $this->context->now,
        ];
    }

    public function flush(): int
    {
        $count = count($this->rows);
        $requeue = [];

        foreach (array_chunk($this->rows, 200) as $chunk) {
            DB::table('sync_conflicts')->insert($chunk);
        }

        foreach ($this->rows as $row) {
            if (ConflictKind::from($row['kind'])->isHubRow()) {
                $requeue[$row['entity']][$row['entity_id']] = true;
            }
        }

        // §19.3: the stored row wins by default, so send it down again (next pull): the shop whose change was kept out
        // gets it, tills that already hold it treat identical content as applied.
        foreach ($requeue as $entity => $ids) {
            DB::table(EntityRegistry::get($entity)->table)->where('company_id', $this->context->companyId)
                ->whereIn('id', array_keys($ids))->update(['hub_version' => null]);
        }

        $this->rows = [];

        return $count;
    }

    public function discard(): void
    {
        $this->rows = [];
    }
}
