<?php

namespace App\Domain\TillData\Actions;

use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Sync\ChangeLedger;
use App\Domain\TillData\Sync\ChunkApplier;
use App\Domain\TillData\Sync\Data\ApplyResult;
use App\Domain\TillData\Sync\Data\MappedChange;
use App\Domain\TillData\Sync\Data\Rejection;
use App\Domain\TillData\Sync\Data\SyncChange;
use App\Domain\TillData\Sync\Enums\ChangeOutcome;
use App\Domain\TillData\Sync\EnvelopeReader;
use App\Domain\TillData\Sync\ParentResolver;
use App\Domain\TillData\Sync\PayloadMapper;
use App\Domain\TillData\Sync\SyncContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The one idempotent way till rows enter the store. The push endpoint (2.2) calls it with a decoded batch;
 * pull (2.5) and tests use it too. Contract v1.3.1: docs/contracts/portal-api-v1.3.3/docs/web-portal-api.md §5-7
 * and §19 (never twice, never echoed, never backwards).
 *
 *     $result = app(ApplySyncChanges::class)->handle($company, $sendingBranch, $changes);
 *     return response()->json($result->toPushReply());   // {acknowledgedSeq, accepted}
 *
 * Every change is validated on its own: a bad change becomes a rejection (with its key), never an exception for
 * the batch. Changes are stored in seq order, in chunks of CHUNK_SIZE, one transaction per chunk. The same batch
 * applied twice gives the same result and changes nothing the second time.
 *
 * The caller must already have authenticated the branch (its sync key) and should hold a per-branch lock so two
 * pushes of one branch never run at once.
 */
final class ApplySyncChanges
{
    public const CHUNK_SIZE = 500;

    public function __construct(
        private readonly EnvelopeReader $reader,
        private readonly PayloadMapper $mapper,
        private readonly ChunkApplier $chunks,
        private readonly ChangeLedger $ledger,
    ) {}

    /**
     * @param  iterable<mixed>  $changes  decoded sync-change envelopes (associative arrays), oldest first
     */
    public function handle(Company $company, Branch $sender, iterable $changes): ApplyResult
    {
        $started = hrtime(true);

        if ($sender->company_id !== $company->getKey()) {
            throw new InvalidArgumentException('The sending branch does not belong to the company.');
        }

        $context = new SyncContext(
            $company->getKey(),
            $sender->getKey(),
            array_fill_keys(DB::table('registers')->where('company_id', $company->getKey())->where('branch_id', $sender->getKey())->pluck('id')->all(), true),
            now('UTC')->format('Y-m-d H:i:s'),
        );

        $read = [];

        foreach ($changes as $raw) {
            $read[] = $this->reader->read($raw, count($read), $context);
        }

        // A retry: changes the ledger already holds are duplicates, no need to validate them again.
        $seqs = array_map(fn ($r) => $r instanceof SyncChange ? $r->seq : 0, $read);
        $known = $this->ledger->applied($context, $seqs);
        $items = [];
        $outcomes = [];

        foreach ($read as $i => $item) {
            if ($item instanceof SyncChange && $item->seq > 0 && isset($known[$item->seq])) {
                $outcomes[$i] = ChangeOutcome::Duplicate;
            }

            $items[] = $item instanceof SyncChange && ! isset($outcomes[$i])
                ? $this->mapper->map($item, EntityRegistry::get($item->entity), $context)
                : $item;
        }

        $order = $this->seqOrder($items);
        $this->rejectRepeatedSeqs($order, $items);

        $mapped = array_values(array_filter(array_map(fn (int $i) => $items[$i], $order), fn ($item) => $item instanceof MappedChange));
        $parents = new ParentResolver($mapped, array_filter($items, fn ($item) => $item instanceof Rejection));

        foreach (array_chunk($mapped, self::CHUNK_SIZE) as $chunk) {
            $outcomes += $this->chunks->apply($chunk, $context, $parents);
        }

        foreach ($items as $i => $item) {
            if ($item instanceof Rejection) {
                $outcomes[$i] = $item;
            }
        }

        $this->warnUnknownEnums($mapped, $context);

        return $this->result($order, $items, $outcomes, $started, $context->now);
    }

    /**
     * Indexes sorted by seq (stable). A change whose seq could not be read keeps its place after the one before it.
     *
     * @param  list<MappedChange|SyncChange|Rejection>  $items
     * @return list<int>
     */
    private function seqOrder(array $items): array
    {
        $keys = [];
        $previous = 0;

        foreach ($items as $i => $item) {
            $previous = self::seqOf($item) ?? $previous;
            $keys[$i] = $previous;
        }

        $order = array_keys($items);
        usort($order, fn (int $a, int $b) => [$keys[$a], $a] <=> [$keys[$b], $b]);

        return $order;
    }

    /**
     * @param  list<int>  $order
     * @param  list<MappedChange|SyncChange|Rejection>  $items
     */
    private function rejectRepeatedSeqs(array $order, array &$items): void
    {
        $seen = [];

        foreach ($order as $i) {
            $seq = self::seqOf($items[$i]);

            if ($seq === null || $seq === 0) {
                continue;
            }

            if (isset($seen[$seq]) && $items[$i] instanceof MappedChange) {
                $items[$i] = Rejection::for($items[$i]->change, 'sync.duplicate_seq', "Seq {$seq} appears more than once in the batch.");
            }

            $seen[$seq] = true;
        }
    }

    /**
     * @param  list<int>  $order
     * @param  list<MappedChange|SyncChange|Rejection>  $items
     * @param  array<int, ChangeOutcome|Rejection>  $outcomes
     */
    private function result(array $order, array $items, array $outcomes, int|float $started, string $now): ApplyResult
    {
        $acknowledged = null;
        $counts = [];
        $rejected = [];
        $stopped = false;

        foreach ($order as $i) {
            $outcome = $outcomes[$i];

            if ($outcome instanceof Rejection) {
                $rejected[] = $outcome;
                $stopped = true;

                continue;
            }

            $counts[$outcome->value] = ($counts[$outcome->value] ?? 0) + 1;

            if (! $stopped) {
                $acknowledged = max($acknowledged ?? 0, (int) self::seqOf($items[$i]));
            }
        }

        if ($acknowledged === null) {
            $first = $order === [] ? null : self::seqOf($items[$order[0]]);
            $acknowledged = max(0, ($first ?? 1) - 1);
        }

        return new ApplyResult($acknowledged, array_sum($counts), $rejected, $counts, (hrtime(true) - $started) / 1e6, str_replace(' ', 'T', $now).'Z');
    }

    /**
     * Enum values the contract does not list yet are stored as sent (never lose a row); say so once per batch.
     *
     * @param  list<MappedChange>  $mapped
     */
    private function warnUnknownEnums(array $mapped, SyncContext $context): void
    {
        $unknown = [];

        foreach ($mapped as $item) {
            foreach ($item->unknownEnums as $value) {
                $unknown[$value] = ($unknown[$value] ?? 0) + 1;
            }
        }

        if ($unknown !== []) {
            Log::warning('Till sync stored enum values the contract does not list. Regenerate after updating the contract.', [
                'company_id' => $context->companyId,
                'branch_id' => $context->branchId,
                'values' => array_slice($unknown, 0, 20, true),
            ]);
        }
    }

    private static function seqOf(MappedChange|SyncChange|Rejection $item): ?int
    {
        return match (true) {
            $item instanceof MappedChange => $item->change->seq,
            default => $item->seq,
        };
    }
}
