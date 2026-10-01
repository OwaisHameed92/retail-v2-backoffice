<?php

namespace App\Domain\Demo\Support;

use App\Domain\Reporting\Demo\DemoShop;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Actions\ApplySyncChanges;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Collects demo rows as a shop's till would push them and stores them through the real push path
 * (`ApplySyncChanges`, ledger stream "demo-seed"), so ids, row versions, scopes and the ledger are exactly as for a
 * till. A row's seq comes from its entity and id: running `demo:seed` again sends the same seqs, which the ledger
 * knows, so nothing is stored twice. `--fresh` finds every demo row through that stream.
 */
final class DemoPush
{
    public const STREAM = 'demo-seed';

    private const BATCH = 4000;

    /** @var array<string, array<string, array<string, mixed>>> branch id => entity|id => envelope */
    private array $queue = [];

    /** @var array<string, Branch> */
    private array $branches = [];

    private int $stored = 0;

    public function __construct(
        private readonly ApplySyncChanges $apply,
        private readonly DemoBusiness $business,
    ) {
        foreach ($business->shops as ['branch' => $branch]) {
            $this->branches[(string) $branch->id] = $branch;
        }
    }

    /**
     * Queues one row, pushed by the given shop's till. `fields` are the entity's members (camelCase).
     *
     * @param  array<string, mixed>  $fields
     */
    public function add(DemoShop $from, string $entity, string $id, array $fields, CarbonImmutable $at, ?CarbonImmutable $created = null): string
    {
        $payload = [
            'id' => $id,
            'companyId' => $this->business->companyId,
            ...$fields,
            'createdAt' => DemoBusiness::iso($created ?? $at),
            'updatedAt' => DemoBusiness::iso($at),
            'rowVersion' => 1,
            'deletedAt' => null,
            'isDeleted' => false,
        ];

        // The same row queued twice (a repeated pick) is sent once, as its last version.
        $this->queue[$from->branchId]["{$entity}|{$id}"] = [
            'seq' => self::seq($entity, $id),
            'entity' => $entity,
            'entityId' => $id,
            'op' => 'I',
            'version' => 1,
            'companyId' => $this->business->companyId,
            'branchId' => '',
            'registerId' => '',
            'at' => $payload['updatedAt'],
            'payload' => $payload,
            'key' => "{$entity}:{$id}:1",
        ];

        if (count($this->queue[$from->branchId]) >= self::BATCH) {
            $this->flushBranch($from->branchId);
        }

        return $id;
    }

    /** Stores everything queued, one transaction per shop. Returns the rows sent so far. */
    public function flush(): int
    {
        foreach (array_keys($this->queue) as $branchId) {
            $this->flushBranch($branchId);
        }

        return $this->stored;
    }

    /** A stable seq per (entity, id): 1 … 2^52, the same on every run. */
    public static function seq(string $entity, string $id): int
    {
        return 1 + (int) hexdec(substr(hash('sha256', "{$entity}|{$id}"), 0, 13));
    }

    private function flushBranch(string $branchId): void
    {
        $changes = array_values($this->queue[$branchId] ?? []);
        unset($this->queue[$branchId]);

        if ($changes === []) {
            return;
        }

        DB::transaction(function () use ($branchId, $changes) {
            $result = $this->apply->handle($this->business->company, $this->branches[$branchId], $changes, self::STREAM);
            $rejected = $result->firstRejection();

            if ($rejected !== null) {
                throw new RuntimeException("A demo row was refused: {$rejected->key} {$rejected->code} {$rejected->message}");
            }
        });

        $this->stored += count($changes);
    }
}
