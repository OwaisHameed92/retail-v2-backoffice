<?php

namespace App\Domain\StockDrafts\Queries;

use App\Domain\Shared\Support\Money;
use App\Domain\StockDrafts\Enums\StockDraftKind;
use App\Domain\StockDrafts\Enums\StockDraftState;
use Illuminate\Support\Facades\DB;

/**
 * Purchase order quantities that include the stock drafts (contract proposal pda-stock-drafts §5.3, §5.4):
 *
 * - pending receipts: deliveries booked on the handheld that the till has not posted yet (pending / with the
 *   till); once the till posts one, its quantity is in the order line's own `receivedQty`.
 * - returned: drafted returns that are not cancelled or refused, plus the till's own returns linked to the line
 *   through their goods-in line (not cancelled).
 * - receiving started: a head-office order with a delivery waiting for the till may no longer be changed on the
 *   portal (§9 "Order lines"): DraftHeadOfficeOrder and the handheld refuse it.
 */
final class PurchaseOrderStock
{
    /**
     * @param  list<string>  $lineIds  purchase order line ids
     * @return array<string, string> line id => base units (4 dp) waiting for the till
     */
    public static function pendingReceived(array $lineIds, ?string $exceptDraftId = null): array
    {
        if ($lineIds === []) {
            return [];
        }

        return DB::table('stock_draft_lines as l')->join('stock_drafts as d', 'd.id', '=', 'l.stock_draft_id')
            ->where('d.kind', StockDraftKind::GoodsReceipt->value)->whereIn('d.state', StockDraftState::OPEN)
            ->when($exceptDraftId !== null, fn ($q) => $q->where('d.id', '!=', $exceptDraftId))
            ->whereIn('l.purchase_order_line_id', $lineIds)
            ->groupBy('l.purchase_order_line_id')->selectRaw('l.purchase_order_line_id as line_id, SUM(l.quantity) as qty')
            ->pluck('qty', 'line_id')->map(fn (mixed $qty) => Money::normalise($qty ?? 0, 4))->all();
    }

    /**
     * @param  list<string>  $lineIds  purchase order line ids
     * @return array<string, string> line id => base units (4 dp) returned or being returned
     */
    public static function returned(array $lineIds, ?string $exceptDraftId = null): array
    {
        if ($lineIds === []) {
            return [];
        }

        $drafted = DB::table('stock_draft_lines as l')->join('stock_drafts as d', 'd.id', '=', 'l.stock_draft_id')
            ->where('d.kind', StockDraftKind::PurchaseReturn->value)->whereIn('d.state', [...StockDraftState::OPEN, StockDraftState::Confirmed->value])
            ->when($exceptDraftId !== null, fn ($q) => $q->where('d.id', '!=', $exceptDraftId))
            ->whereIn('l.purchase_order_line_id', $lineIds)
            ->groupBy('l.purchase_order_line_id')->selectRaw('l.purchase_order_line_id as line_id, SUM(l.quantity) as qty')
            ->pluck('qty', 'line_id')->all();

        // The till's own returns (never one of our drafts: those carry no goods-in line).
        $tills = DB::table('purchase_return_lines as rl')->join('purchase_returns as r', 'r.id', '=', 'rl.purchase_return_id')
            ->join('goods_receipt_lines as gl', 'gl.id', '=', 'rl.goods_receipt_line_id')
            ->where('r.status', '!=', 'cancelled')->whereNull('r.deleted_at')->whereNull('rl.deleted_at')
            ->whereIn('gl.purchase_order_line_id', $lineIds)
            ->whereNotIn('r.id', DB::table('stock_drafts')->select('id')->where('kind', StockDraftKind::PurchaseReturn->value))
            ->groupBy('gl.purchase_order_line_id')->selectRaw('gl.purchase_order_line_id as line_id, SUM(rl.qty) as qty')
            ->pluck('qty', 'line_id')->all();

        $returned = [];

        foreach (array_unique([...array_keys($drafted), ...array_keys($tills)]) as $line) {
            $returned[(string) $line] = Money::add($drafted[$line] ?? 0, $tills[$line] ?? 0, 4);
        }

        return $returned;
    }

    /** A delivery against this order is waiting for the till (drafted, not posted or refused yet). */
    public static function receivingStarted(string $orderId): bool
    {
        return DB::table('stock_drafts')->where('purchase_order_id', $orderId)->where('kind', StockDraftKind::GoodsReceipt->value)
            ->whereIn('state', StockDraftState::OPEN)->exists();
    }

    /**
     * @param  list<string>  $orderIds
     * @return array<string, true> order ids with a delivery waiting for the till
     */
    public static function ordersReceiving(array $orderIds): array
    {
        $receiving = [];
        $ids = $orderIds === [] ? [] : DB::table('stock_drafts')->whereIn('purchase_order_id', $orderIds)->where('kind', StockDraftKind::GoodsReceipt->value)
            ->whereIn('state', StockDraftState::OPEN)->distinct()->pluck('purchase_order_id')->all();

        foreach ($ids as $id) {
            $receiving[(string) $id] = true;
        }

        return $receiving;
    }
}
