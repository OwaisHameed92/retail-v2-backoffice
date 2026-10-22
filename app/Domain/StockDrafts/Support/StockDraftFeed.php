<?php

namespace App\Domain\StockDrafts\Support;

use App\Domain\StockDrafts\Actions\ReconcileStockDrafts;
use App\Domain\StockDrafts\Enums\StockDraftState;
use App\Domain\StockDrafts\Models\StockDraft;
use App\Domain\StockDrafts\Models\StockDraftLine;
use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Support\PullPayload;
use App\Domain\TillData\Sync\HubVersions;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stock drafts in a shop's pull (contract proposal pda-stock-drafts §2, §5, §9), only while StockDraftSwitch serves
 * the till: PullChanges asks; with the switch off nothing here runs and the pull is exactly as before.
 *
 * - `prepare()`: before the page, records the outcomes the till pushed (ReconcileStockDrafts) and stamps any draft
 *   whose after-commit stamp did not run.
 * - `sources()`: the page's extra rows — the shop's open drafts (pending or with the till) and their lines, with
 *   `hub_version > since`, labelled HEADER / LINE (never an EntityRegistry name).
 * - `envelopes()`: inside the pull's transaction, locks the page's drafts (as an edit or cancel does), builds the
 *   envelopes from their current rows (a row changed since the page was read waits for its newer version) and marks
 *   every draft it sends `withTill`: from then on the portal never changes it (409 on the handheld). A draft is
 *   always sent as `I`; a confirmed, rejected or cancelled draft is never sent again.
 */
final class StockDraftFeed
{
    public const HEADER = '#StockDraft';

    public const LINE = '#StockDraftLine';

    public function __construct(private readonly HubVersions $versions, private readonly ReconcileStockDrafts $reconcile) {}

    public static function owns(string $entity): bool
    {
        return $entity === self::HEADER || $entity === self::LINE;
    }

    public function prepare(string $companyId): void
    {
        $this->reconcile->handle($companyId);

        $waiting = StockDraft::withoutCompanyScope()->where('company_id', $companyId)->where('state', StockDraftState::Pending->value)
            ->where(fn ($q) => $q->whereNull('hub_version')->orWhereIn('id', StockDraftLine::withoutCompanyScope()->select('stock_draft_id')
                ->where('company_id', $companyId)->whereNull('hub_version')))
            ->orderBy('created_at')->pluck('id');

        foreach ($waiting as $id) {
            $lines = StockDraftLine::withoutCompanyScope()->where('stock_draft_id', $id)->orderBy('position')->pluck('id')->map(fn ($l) => (string) $l)->all();
            $this->versions->stampTables($companyId, [['stock_drafts', [(string) $id]], ['stock_draft_lines', $lines]]);
        }
    }

    /**
     * @return list<Builder> union members with PullFeed's columns (entity, id, version, departed_from, departed_at)
     */
    public function sources(string $companyId, string $branchId, int $since): array
    {
        return [
            DB::table('stock_drafts')
                ->selectRaw('? as entity, id, hub_version as version, NULL as departed_from, NULL as departed_at', [self::HEADER])
                ->where('company_id', $companyId)->where('branch_id', $branchId)
                ->whereIn('state', StockDraftState::OPEN)->where('hub_version', '>', $since),
            DB::table('stock_draft_lines')
                ->selectRaw('? as entity, stock_draft_lines.id as id, stock_draft_lines.hub_version as version, NULL as departed_from, NULL as departed_at', [self::LINE])
                ->join('stock_drafts', 'stock_drafts.id', '=', 'stock_draft_lines.stock_draft_id')
                ->where('stock_draft_lines.company_id', $companyId)->where('stock_drafts.branch_id', $branchId)
                ->whereIn('stock_drafts.state', StockDraftState::OPEN)->where('stock_draft_lines.hub_version', '>', $since),
        ];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: mixed}>  $page  the pull page (other entities are ignored)
     * @return array<string, array<string, mixed>> "label:id" => envelope
     */
    public function envelopes(PullPayload $ids, string $companyId, string $branchId, array $page): array
    {
        $headers = [];
        $lineVersions = [];

        foreach ($page as [$entity, $id, $version]) {
            if ($entity === self::HEADER) {
                $headers[$id] = $version;
            } elseif ($entity === self::LINE) {
                $lineVersions[$id] = $version;
            }
        }

        if ($headers === [] && $lineVersions === []) {
            return [];
        }

        // Headers first, in id order, under the same lock an edit or a cancel takes; then their lines.
        $parents = $lineVersions === [] ? [] : DB::table('stock_draft_lines')->whereIn('id', array_keys($lineVersions))->pluck('stock_draft_id')->all();
        $draftIds = array_values(array_unique([...array_map('strval', array_keys($headers)), ...array_map('strval', $parents)]));
        sort($draftIds);
        $drafts = StockDraft::withoutCompanyScope()->where('company_id', $companyId)->where('branch_id', $branchId)->whereIn('id', $draftIds)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $lines = StockDraftLine::withoutCompanyScope()->whereIn('stock_draft_id', $drafts->keys()->all())->orderBy('stock_draft_id')->orderBy('position')
            ->lockForUpdate()->get();
        $byDraft = $lines->groupBy('stock_draft_id');
        $byId = $lines->keyBy('id');
        $payloads = new StockDraftPayloads($ids);
        $envelopes = [];
        $sent = [];

        foreach ($headers as $id => $version) {
            $draft = $drafts->get($id);

            if ($draft instanceof StockDraft && $draft->state->isOpen() && $draft->hub_version === $version) {
                $envelopes[self::HEADER.':'.$id] = $this->envelope($ids, $draft->kind->entity(), $draft->id, $version, $draft, $payloads->header($draft, $byDraft->get($draft->id, collect())));
                $sent[] = $draft->id;
            }
        }

        foreach ($lineVersions as $id => $version) {
            $line = $byId->get($id);
            $draft = $line instanceof StockDraftLine ? $drafts->get($line->stock_draft_id) : null;
            $entity = $draft instanceof StockDraft ? $draft->kind->lineEntity() : null;

            if ($line instanceof StockDraftLine && $draft instanceof StockDraft && $entity !== null && $draft->state->isOpen() && $line->hub_version === $version) {
                $envelopes[self::LINE.':'.$id] = $this->envelope($ids, $entity, $line->id, $version, $draft, $payloads->line($line, $draft));
                $sent[] = $draft->id;
            }
        }

        if ($sent !== []) {
            // Query builder: the drafts' own times stay as sent (a repeated page carries the same content).
            DB::table('stock_drafts')->whereIn('id', array_values(array_unique($sent)))->where('state', StockDraftState::Pending->value)
                ->update(['state' => StockDraftState::WithTill->value, 'pulled_at' => now('UTC')->format('Y-m-d H:i:s')]);
        }

        return $envelopes;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function envelope(PullPayload $ids, string $entity, string $id, int $version, StockDraft $draft, array $payload): array
    {
        return [
            'seq' => 0,
            'entity' => $entity,
            'entityId' => $id,
            'op' => 'I',
            'version' => $version,
            'companyId' => $ids->till(IdKind::Company, $draft->company_id),
            'branchId' => $ids->till(IdKind::Branch, $draft->branch_id),
            'registerId' => '',
            'at' => $payload['updatedAt'] ?? PullPayload::dateTime(now('UTC')),
            'payload' => $payload,
            'key' => "{$entity}:{$id}:{$version}",
        ];
    }
}
