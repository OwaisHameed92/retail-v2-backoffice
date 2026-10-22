<?php

namespace App\Domain\StockDrafts\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\StockDrafts\Data\StockDraftData;
use App\Domain\StockDrafts\Enums\StockDraftKind;
use App\Domain\StockDrafts\Enums\StockDraftState;
use App\Domain\StockDrafts\Exceptions\StockDraftLocked;
use App\Domain\StockDrafts\Models\StockDraft;
use App\Domain\StockDrafts\Models\StockDraftLine;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Sync\HubVersions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Writes a stock draft for one shop (contract proposal pda-stock-drafts §5) and stamps it for that shop's pull:
 * the header, then its lines (parents first, §8). The caller has checked the content.
 *
 * - **Create** with the handheld's `client_reference`: a reference already stored for this shop and kind makes
 *   nothing and answers the draft made the first time, as it is now. Race-safe: the unique index
 *   `stock_drafts_shop_kind_ref_unique` decides; the loser reads the winner's draft. A per-shop number (HS-/HR-)
 *   taken by a parallel create is retried with the next one.
 * - **Update** only while the portal owns the draft (`pending`, never sent in a pull): the header changes and the
 *   lines are replaced (the till never saw the old ones, so nothing has to be deleted on the till); it gets a new
 *   pull version. Otherwise StockDraftLocked.
 */
final class SaveStockDraft
{
    private const ATTEMPTS = 3;

    public function __construct(private readonly HubVersions $versions, private readonly RecordAudit $audit) {}

    /**
     * @return array{0: StockDraft, 1: bool} the draft and whether this call made it
     */
    public function create(Branch $shop, StockDraftData $data): array
    {
        $existing = self::existing($shop, $data->kind, $data->clientReference);

        if ($existing !== null) {
            return [$existing, false];
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                $draft = DB::transaction(fn (): StockDraft => $this->insert($shop, $data));

                break;
            } catch (UniqueConstraintViolationException $e) {
                $existing = self::existing($shop, $data->kind, $data->clientReference);

                if ($existing !== null) {
                    return [$existing, false];
                }

                if ($attempt >= self::ATTEMPTS) {
                    throw $e;
                }
            }
        }

        $this->stamp($draft);
        $this->audit->handle('stock_draft.drafted', $draft, null, self::summary($draft), companyId: $draft->company_id);

        return [self::fresh($draft), true];
    }

    /**
     * @throws StockDraftLocked
     */
    public function update(StockDraft $draft, StockDraftData $data): StockDraft
    {
        $saved = DB::transaction(function () use ($draft, $data): StockDraft {
            $current = StockDraft::withoutCompanyScope()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            StockDraftLocked::unlessPending($current);

            $current->forceFill([...$data->header, 'hub_version' => null])->save();
            StockDraftLine::withoutCompanyScope()->where('stock_draft_id', $current->id)->delete();
            $this->insertLines($current, $data->lines);

            return $current;
        });

        $this->stamp($saved);
        $this->audit->handle('stock_draft.changed', $saved, null, self::summary($saved), companyId: $saved->company_id);

        return self::fresh($saved);
    }

    private function insert(Branch $shop, StockDraftData $data): StockDraft
    {
        $number = $data->kind->numberPrefix() === null ? null : 1 + (int) StockDraft::withoutCompanyScope()
            ->where('company_id', $shop->company_id)->where('branch_id', $shop->id)->where('kind', $data->kind->value)->max('number');

        $draft = StockDraft::withoutCompanyScope()->create([
            ...$data->header,
            'company_id' => $shop->company_id,
            'branch_id' => $shop->id,
            'kind' => $data->kind,
            'state' => StockDraftState::Pending,
            'client_reference' => $data->clientReference,
            'handheld_user_id' => $data->handheldUserId,
            'entered_by' => mb_substr($data->enteredBy, 0, 120),
            'number' => $number,
            'reference' => $number === null ? null : sprintf('%s-%s-%06d', $data->kind->numberPrefix(), $shop->code, $number),
        ]);

        $this->insertLines($draft, $data->lines);

        return $draft;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function insertLines(StockDraft $draft, array $lines): void
    {
        foreach ($lines as $i => $line) {
            StockDraftLine::withoutCompanyScope()->create([...$line, 'company_id' => $draft->company_id, 'stock_draft_id' => $draft->id, 'position' => $i + 1]);
        }
    }

    /** The header, then its lines in order, get the next pull versions (§8 parents first). */
    private function stamp(StockDraft $draft): void
    {
        $lines = StockDraftLine::withoutCompanyScope()->where('stock_draft_id', $draft->id)->orderBy('position')->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->versions->stampTables($draft->company_id, [['stock_drafts', [$draft->id]], ['stock_draft_lines', $lines]]);
    }

    /** The shop's draft of this kind already made with this client reference, as it is now. */
    public static function existing(Branch $shop, StockDraftKind $kind, ?string $clientReference): ?StockDraft
    {
        return $clientReference === null ? null : StockDraft::withoutCompanyScope()->with('lines')->where('company_id', $shop->company_id)
            ->where('branch_id', $shop->id)->where('kind', $kind->value)->where('client_reference', $clientReference)->first();
    }

    private static function fresh(StockDraft $draft): StockDraft
    {
        return StockDraft::withoutCompanyScope()->with('lines')->findOrFail($draft->id);
    }

    /**
     * @return array<string, mixed>
     */
    private static function summary(StockDraft $draft): array
    {
        return ['kind' => $draft->kind->value, 'reference' => $draft->reference, 'product_id' => $draft->product_id,
            'purchase_order_id' => $draft->purchase_order_id, 'lines' => StockDraftLine::withoutCompanyScope()->where('stock_draft_id', $draft->id)->count()];
    }
}
