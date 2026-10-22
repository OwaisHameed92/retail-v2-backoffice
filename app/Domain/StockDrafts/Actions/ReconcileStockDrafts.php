<?php

namespace App\Domain\StockDrafts\Actions;

use App\Domain\Shared\Support\ApiDate;
use App\Domain\Shared\Support\Money;
use App\Domain\StockDrafts\Enums\StockDraftKind;
use App\Domain\StockDrafts\Enums\StockDraftState;
use App\Domain\StockDrafts\Models\StockDraft;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reads the till's outcome of the stock drafts it holds (contract proposal pda-stock-drafts §6) from what it pushed
 * as the shop's own rows, with the draft's id, and records it on the draft. Nothing in the push path changes:
 *
 * | Draft            | The till's row (same id)                       | Confirmed when            | Rejected when                      |
 * |------------------|------------------------------------------------|---------------------------|------------------------------------|
 * | adjustment       | `StockAdjustmentDraft` (kept raw in            | `status` confirmed        | `status` rejected (`rejectReason`) |
 * |                  | `till_unknown_rows` until the portal knows it) |                           |                                    |
 * | stock take       | `stock_takes` (+ `stock_take_lines`)           | `approved`                | `cancelled` (`cancelReason`)       |
 * | goods receipt    | `goods_receipts`                               | `posted`                  | `cancelled` (`cancelReason`)       |
 * | purchase return  | `purchase_returns`                             | `sent` / `credited`       | `cancelled` (`cancelReason`)       |
 *
 * Only the shop the draft was made for counts. The first outcome is kept (later changes at the till show on the
 * till's own document). Cheap when nothing is open: one indexed read. Called before every pull of the business
 * and before the handheld reads or changes a shop's drafts.
 */
final class ReconcileStockDrafts
{
    public function handle(string $companyId, ?string $branchId = null): int
    {
        $open = StockDraft::withoutCompanyScope()->where('company_id', $companyId)->whereIn('state', StockDraftState::OPEN)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['id', 'kind', 'branch_id', 'state']);
        $done = 0;

        foreach ($open->groupBy(fn (StockDraft $d) => $d->kind->value) as $kind => $drafts) {
            $branches = $drafts->pluck('branch_id', 'id')->all();
            $outcomes = match (StockDraftKind::from((string) $kind)) {
                StockDraftKind::Adjustment => $this->adjustments($companyId, $branches),
                StockDraftKind::StockTake => $this->documents($companyId, $branches, 'stock_takes', ['approved'], 'approved_at'),
                StockDraftKind::GoodsReceipt => $this->documents($companyId, $branches, 'goods_receipts', ['posted'], 'posted_at'),
                StockDraftKind::PurchaseReturn => $this->documents($companyId, $branches, 'purchase_returns', ['sent', 'credited'], 'sent_at'),
            };

            foreach ($outcomes as $id => $columns) {
                $done += StockDraft::withoutCompanyScope()->whereKey($id)->whereIn('state', StockDraftState::OPEN)->update([...$columns, 'updated_at' => now('UTC')]);

                if ($kind === StockDraftKind::StockTake->value && $columns['state'] === StockDraftState::Confirmed->value) {
                    $this->countedLines($companyId, $id);
                }
            }
        }

        return $done;
    }

    /**
     * @param  array<string, string>  $branches  draft id => shop
     * @return array<string, array<string, mixed>>
     */
    private function adjustments(string $companyId, array $branches): array
    {
        $outcomes = [];
        $rows = DB::table('till_unknown_rows')->where('company_id', $companyId)->where('entity', 'StockAdjustmentDraft')
            ->whereIn('entity_id', array_keys($branches))->get(['entity_id', 'branch_id', 'payload']);

        foreach ($rows as $row) {
            $payload = is_string($row->payload) ? json_decode($row->payload, true) : null;
            $status = is_array($payload) ? ($payload['status'] ?? null) : null;

            if ($row->branch_id !== ($branches[$row->entity_id] ?? null) || ! in_array($status, ['confirmed', 'rejected'], true)) {
                continue;
            }

            $decimal = fn (string $key) => is_numeric($payload[$key] ?? null) ? Money::normalise($payload[$key], 4) : null;
            $outcomes[(string) $row->entity_id] = $status === 'confirmed'
                ? ['state' => StockDraftState::Confirmed->value, 'outcome_at' => self::time($payload['confirmedAt'] ?? null), 'qty_before' => $decimal('qtyBefore'),
                    'qty_delta' => $decimal('qtyDelta'), 'qty_after' => $decimal('qtyAfter'),
                    'stock_movement_id' => is_string($payload['stockMovementId'] ?? null) ? $payload['stockMovementId'] : null]
                : ['state' => StockDraftState::Rejected->value, 'outcome_at' => self::time($payload['rejectedAt'] ?? null),
                    'reject_reason' => self::reason($payload['rejectReason'] ?? null)];
        }

        return $outcomes;
    }

    /**
     * @param  array<string, string>  $branches  draft id => shop
     * @param  list<string>  $confirmed  the till's statuses that mean "applied"
     * @return array<string, array<string, mixed>>
     */
    private function documents(string $companyId, array $branches, string $table, array $confirmed, string $atColumn): array
    {
        $outcomes = [];
        $columns = ['id', 'branch_id', 'status', 'cancelled_at', 'extra', $atColumn, ...($table === 'goods_receipts' ? ['cancel_reason'] : [])];

        foreach (DB::table($table)->where('company_id', $companyId)->whereIn('id', array_keys($branches))->get($columns) as $row) {
            if ($row->branch_id !== ($branches[$row->id] ?? null)) {
                continue;
            }

            if (in_array($row->status, $confirmed, true)) {
                $outcomes[(string) $row->id] = ['state' => StockDraftState::Confirmed->value, 'outcome_at' => self::time($row->{$atColumn})];
            } elseif ($row->status === 'cancelled') {
                $extra = is_string($row->extra) ? json_decode($row->extra, true) : null;
                $reason = $row->cancel_reason ?? (is_array($extra) ? ($extra['cancelReason'] ?? null) : null);
                $outcomes[(string) $row->id] = ['state' => StockDraftState::Rejected->value, 'outcome_at' => self::time($row->cancelled_at), 'reject_reason' => self::reason($reason)];
            }
        }

        return $outcomes;
    }

    /** A confirmed stock take: each line's expected quantity (the till's stock at apply) and variance. */
    private function countedLines(string $companyId, string $draftId): void
    {
        $lines = DB::table('stock_take_lines')->where('company_id', $companyId)->where('stock_take_id', $draftId)->get(['id', 'snapshot_qty', 'variance_qty']);

        foreach ($lines as $line) {
            DB::table('stock_draft_lines')->where('stock_draft_id', $draftId)->where('id', $line->id)->update([
                'expected_quantity' => Money::normalise($line->snapshot_qty ?? 0, 4), 'variance' => Money::normalise($line->variance_qty ?? 0, 4),
            ]);
        }
    }

    private static function time(mixed $value): string
    {
        try {
            $time = ApiDate::parse($value);
        } catch (InvalidArgumentException) {
            $time = null;
        }

        return ($time ?? CarbonImmutable::now('UTC'))->format('Y-m-d H:i:s');
    }

    private static function reason(mixed $reason): string
    {
        $text = is_string($reason) ? trim($reason) : '';

        return $text === '' ? 'Refused by the shop’s till.' : mb_substr($text, 0, 500);
    }
}
