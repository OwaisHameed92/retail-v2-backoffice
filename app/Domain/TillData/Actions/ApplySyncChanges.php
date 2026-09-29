<?php

namespace App\Domain\TillData\Actions;

use App\Domain\Sync\Support\IdTranslator;
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
use App\Domain\TillData\Sync\NeverStored;
use App\Domain\TillData\Sync\ParentResolver;
use App\Domain\TillData\Sync\PayloadMapper;
use App\Domain\TillData\Sync\SyncContext;
use App\Domain\TillData\Sync\UnknownEntityRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The one idempotent way till rows enter the store. The push endpoint (2.2) calls it with a decoded batch;
 * pull (2.5) and tests use it too. Contract v1.4.1: docs/contracts/portal-api-v1.4.1/docs/web-portal-api.md §5-7
 * and §19 (never twice, never echoed, never backwards).
 *
 *     $result = app(ApplySyncChanges::class)->handle($company, $sendingBranch, $changes);
 *     return response()->json($result->toPushReply());   // {acknowledgedSeq, accepted, receivedAt}
 *
 * Every change is validated on its own: a bad change becomes a rejection (with its key), never an exception for
 * the batch. Changes are stored in seq order, in chunks of CHUNK_SIZE, one transaction per chunk. The same batch
 * applied twice gives the same result and changes nothing the second time.
 *
 * The caller must already have authenticated the branch (its sync key) and should hold a per-branch lock so two
 * pushes of one branch never run at once.
 *
 * Module 2.1: the till keeps its own company/branch/register ids. Every envelope first goes through the company's
 * IdTranslator (id_map), so rows carrying the till's ids are checked and stored under ours; ids with no mapping
 * are checked as sent.
 */
final class ApplySyncChanges
{
    public const CHUNK_SIZE = 500;

    public function __construct(
        private readonly EnvelopeReader $reader,
        private readonly PayloadMapper $mapper,
        private readonly ChunkApplier $chunks,
        private readonly ChangeLedger $ledger,
        private readonly UnknownEntityRows $unknown,
    ) {}

    /**
     * @param  iterable<mixed>  $changes  decoded sync-change envelopes (associative arrays), oldest first
     * @param  string  $stream  '' for a delta push (seq = the branch's ChangeLog); an initial upload's id (§17.8)
     */
    public function handle(Company $company, Branch $sender, iterable $changes, string $stream = ''): ApplyResult
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
            $stream,
        );

        $ids = IdTranslator::forCompany($company->getKey());
        $read = [];

        foreach ($changes as $raw) {
            $read[] = $this->reader->read($ids->change($raw), count($read), $context);
        }

        // A retry: changes the ledger already holds are duplicates, no need to validate them again.
        $seqs = array_map(fn ($r) => $r instanceof SyncChange ? $r->seq : 0, $read);
        $known = $this->ledger->applied($context, $seqs);
        $items = [];
        $outcomes = [];

        foreach ($read as $i => $item) {
            if ($item instanceof SyncChange && $item->seq > 0 && isset($known[$item->seq])) {
                $outcomes[$i] = ChangeOutcome::Duplicate;
            } elseif ($item instanceof SyncChange && NeverStored::matches($item)) {
                $outcomes[$i] = ChangeOutcome::Skipped;
            }

            $items[] = $item instanceof SyncChange && ! isset($outcomes[$i]) && ! UnknownEntityRows::matches($item)
                ? $this->mapper->map($item, EntityRegistry::get($item->entity), $context)
                : $item;
        }

        $order = $this->seqOrder($items);
        $this->rejectRepeatedSeqs($order, $items, $outcomes);

        $mapped = array_values(array_filter(array_map(fn (int $i) => $items[$i], $order), fn ($item) => $item instanceof MappedChange));
        $parents = new ParentResolver($mapped, array_filter($items, fn ($item) => $item instanceof Rejection));

        foreach (array_chunk($mapped, self::CHUNK_SIZE) as $chunk) {
            $outcomes += $this->chunks->apply($chunk, $context, $parents);
        }

        // Entities we do not know yet (a newer till): kept raw, never a rejection (§18.8, §21.1).
        $unknown = [];

        foreach ($order as $i) {
            if ($items[$i] instanceof SyncChange && ! isset($outcomes[$i])) {
                $unknown[] = $items[$i];
            }
        }

        $outcomes += $this->unknown->store($unknown, $context);

        foreach ($items as $i => $item) {
            if ($item instanceof Rejection) {
                $outcomes[$i] = $item;
            }
        }

        $this->warnUnknownEnums($mapped, $context);
        NeverStored::log($items, $outcomes, $context);
        $receivedAt = $this->ledger->receivedAt($context, NeverStored::storedSeqs($items, $outcomes), NeverStored::acceptedSeqs($items, $outcomes));

        return $this->result($order, $items, $outcomes, $started, $receivedAt);
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
     * @param  array<int, ChangeOutcome>  $outcomes  outcomes already known (duplicates, skipped)
     */
    private function rejectRepeatedSeqs(array $order, array &$items, array $outcomes): void
    {
        $seen = [];

        foreach ($order as $i) {
            $seq = self::seqOf($items[$i]);

            if ($seq === null || $seq === 0) {
                continue;
            }

            $change = match (true) {
                $items[$i] instanceof MappedChange => $items[$i]->change,
                $items[$i] instanceof SyncChange && ! isset($outcomes[$i]) => $items[$i],
                default => null,
            };

            if (isset($seen[$seq]) && $change !== null) {
                $items[$i] = Rejection::for($change, 'sync.duplicate_seq', "Seq {$seq} appears more than once in the batch.");
            }

            $seen[$seq] = true;
        }
    }

    /**
     * @param  list<int>  $order
     * @param  list<MappedChange|SyncChange|Rejection>  $items
     * @param  array<int, ChangeOutcome|Rejection>  $outcomes
     */
    private function result(array $order, array $items, array $outcomes, int|float $started, string $receivedAt): ApplyResult
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

        return new ApplyResult($acknowledged, array_sum($counts), $rejected, $counts, (hrtime(true) - $started) / 1e6, $receivedAt);
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
