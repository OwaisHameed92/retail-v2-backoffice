<?php

namespace App\Domain\StockDrafts\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\StockDrafts\Enums\StockDraftState;
use App\Domain\StockDrafts\Exceptions\StockDraftLocked;
use App\Domain\StockDrafts\Models\StockDraft;
use Illuminate\Support\Facades\DB;

/**
 * Withdraws a stock draft the portal still owns (`pending`: never sent in a pull). It simply leaves the pull: the
 * till never saw it, so nothing is sent down. Once the till has it (or it is already cancelled): StockDraftLocked.
 * Race-safe with the pull, which marks the drafts it sends under the same row lock (StockDraftFeed).
 */
final class CancelStockDraft
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws StockDraftLocked
     */
    public function handle(StockDraft $draft, ?string $reason = null): StockDraft
    {
        $cancelled = DB::transaction(function () use ($draft, $reason): StockDraft {
            $current = StockDraft::withoutCompanyScope()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            StockDraftLocked::unlessPending($current);

            $current->forceFill([
                'state' => StockDraftState::Cancelled,
                'cancelled_at' => now('UTC'),
                'cancel_reason' => trim((string) $reason) === '' ? null : mb_substr(trim((string) $reason), 0, 255),
            ])->save();

            return $current;
        });

        $this->audit->handle('stock_draft.cancelled', $cancelled, ['state' => 'pending'], ['state' => 'cancelled', 'kind' => $cancelled->kind->value],
            companyId: $cancelled->company_id);

        return StockDraft::withoutCompanyScope()->with('lines')->findOrFail($cancelled->id);
    }
}
