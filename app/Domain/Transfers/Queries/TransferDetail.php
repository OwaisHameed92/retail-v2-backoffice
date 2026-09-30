<?php

namespace App\Domain\Transfers\Queries;

use App\Domain\Purchasing\Support\PurchasingNames;
use App\Domain\Shared\Support\Money;
use App\Domain\Sync\Models\SyncBranchStatus;
use App\Domain\TillData\Models\StockTransfer;
use App\Domain\TillData\Models\StockTransferLine;
use App\Domain\TillData\Models\StockTransferReceipt;
use App\Domain\TillData\Models\StockTransferReceiptLine;
use App\Domain\TillData\Models\TillUser;
use App\Domain\Transfers\Support\TransferFigures;
use Illuminate\Database\Eloquent\Model;

/**
 * One stock transfer (module 5.3), read only: what was sent against what arrived line by line (discrepancies at cost),
 * the receiving shop's receipt, and the relay: whether the receiving till has pulled the transfer, and whether the
 * sending till has pulled the receipt back (contract §10.2).
 */
final class TransferDetail
{
    /**
     * @param  StockTransfer  $transfer  loaded by TransferState::query() (with `state` and `relay`)
     * @return array<string, mixed>
     */
    public static function for(StockTransfer $transfer): array
    {
        $lines = StockTransferLine::query()->where('transfer_id', $transfer->id)->orderBy('product_name')->get();
        $receipt = StockTransferReceipt::query()->where('transfer_id', $transfer->id)->orderByDesc('received_at')->orderByDesc('id')->first();
        $received = $receipt === null ? collect() : StockTransferReceiptLine::query()->where('receipt_id', $receipt->id)->get();
        $names = PurchasingNames::for([...$lines->all(), ...$received->all()]);
        $shops = TransferList::shopNames();
        $users = TillUser::query()->withTrashed()->whereKey(array_filter([$transfer->requested_by_user_id, $transfer->dispatched_by_user_id, $receipt?->received_by_user_id]))
            ->pluck('name', 'id')->all();
        $byLine = $received->keyBy('transfer_line_id');
        $used = [];
        $rows = [];

        foreach ($lines as $line) {
            $got = $byLine->get($line->id) ?? $received->first(fn (StockTransferReceiptLine $r) => $r->product_id === $line->product_id && ! in_array($r->id, $used, true));
            $used[] = $got?->id;
            $rows[] = self::line($line->id, $names->product($line->product_id, $line->product_name), $line->qty_requested, $line->qty_dispatched, $got, $line->unit_cost, $receipt !== null);
        }

        foreach ($received->reject(fn (StockTransferReceiptLine $r) => in_array($r->id, $used, true)) as $extra) {
            $rows[] = self::line($extra->id, $names->product($extra->product_id), null, $extra->qty_dispatched, $extra, $extra->unit_cost, true);
        }

        $sum = fn (string $key) => Money::sum(array_map(fn (array $r) => $r[$key] ?? '0', $rows));
        $qty = fn (string $key) => Money::sum(array_map(fn (array $r) => $r[$key] ?? '0', $rows), Money::QUANTITY_SCALE);
        $user = fn (?string $id) => $id === null || $id === '' ? null : ($users[$id] ?? null);

        return [
            'transfer' => [
                'id' => $transfer->id,
                'reference' => $transfer->reference,
                'status' => (string) $transfer->getAttribute('state'),
                'tillStatus' => $transfer->status?->value,
                'from' => $shops[$transfer->from_branch_id] ?? 'Unknown shop',
                'fromId' => $transfer->from_branch_id,
                'to' => $shops[$transfer->to_branch_id] ?? 'Unknown shop',
                'toId' => $transfer->to_branch_id,
                'isReturn' => (bool) $transfer->is_return,
                'returnOf' => self::returnOf($transfer->return_of_transfer_id),
                'note' => $transfer->note ?: null,
                'requestedAt' => TransferList::iso($transfer->getAttribute('requested_at')),
                'requestedBy' => $user($transfer->requested_by_user_id),
                'dispatchedAt' => TransferList::iso($transfer->getAttribute('dispatched_at')),
                'dispatchedBy' => $user($transfer->dispatched_by_user_id),
                'dispatchedCost' => Money::normalise($transfer->dispatched_cost ?? 0),
                'lineCount' => (int) $transfer->line_count,
                'updatedAt' => TransferList::iso($transfer->getAttribute('updated_at')),
            ],
            'receipt' => $receipt === null ? null : [
                'status' => $receipt->status?->value,
                'receivedAt' => TransferList::iso($receipt->getAttribute('received_at')),
                'receivedBy' => $user($receipt->received_by_user_id),
                'closedAt' => TransferList::iso($receipt->getAttribute('closed_at')),
                'note' => $receipt->note ?: null,
            ],
            'lines' => $rows,
            'totals' => [
                'sent' => $qty('sent'), 'received' => $receipt === null ? null : $qty('received'), 'variance' => $receipt === null ? null : $qty('variance'),
                'sentValue' => $sum('sentValue'), 'receivedValue' => $receipt === null ? null : $sum('receivedValue'),
                'varianceValue' => $receipt === null ? null : $sum('varianceValue'),
                'discrepancies' => count(array_filter($rows, fn (array $r) => $r['discrepancy'])),
            ],
            'relay' => self::relay($transfer, $lines->all(), $receipt, $received->all(), $shops),
        ];
    }

    /**
     * @param  array{name: string, sku: string|null}  $product
     * @return array<string, mixed>
     */
    private static function line(string $id, array $product, ?string $requested, ?string $sent, ?StockTransferReceiptLine $got, ?string $unitCost, bool $hasReceipt): array
    {
        $cost = $got->unit_cost ?? $unitCost ?? '0';
        $f = TransferFigures::line($got->qty_dispatched ?? $sent, $got->qty_received ?? '0', $cost);
        // A line the receipt does not list (yet) is not compared: its receipt line may still be on its way.
        $compared = $hasReceipt && $got !== null;

        return [
            'id' => $id,
            'product' => $product,
            'requested' => $requested,
            'sent' => $f['sent'],
            'received' => $compared ? $f['received'] : null,
            'variance' => $compared ? $f['variance'] : null,
            'unitCost' => Money::normalise($cost, Money::QUANTITY_SCALE),
            'sentValue' => $f['sentValue'],
            'receivedValue' => $compared ? $f['receivedValue'] : null,
            'varianceValue' => $compared ? $f['varianceValue'] : null,
            'discrepancy' => $compared && ! Money::isZero($f['variance']),
            'missingOnReceipt' => $hasReceipt && $got === null,
        ];
    }

    /** @return array{id: string, reference: string}|null */
    private static function returnOf(?string $id): ?array
    {
        $original = $id === null || $id === '' ? null : StockTransfer::query()->find($id, ['id', 'reference']);

        return $original === null ? null : ['id' => $original->id, 'reference' => $original->reference];
    }

    /**
     * The two relays of §10.2 for this transfer: the transfer (+ lines) to the receiving shop (TransferState's `relay`),
     * the receipt (+ lines) back to the sending shop (`notRelayed`, `waiting`, `sent` or `stored`, the same rules).
     *
     * @param  list<StockTransferLine>  $lines
     * @param  list<StockTransferReceiptLine>  $receiptLines  the receipt's lines (they travel with it)
     * @param  array<string, string>  $shops
     * @return array<string, mixed>
     */
    private static function relay(StockTransfer $transfer, array $lines, ?StockTransferReceipt $receipt, array $receiptLines, array $shops): array
    {
        $status = SyncBranchStatus::query()->whereIn('branch_id', [$transfer->from_branch_id, $transfer->to_branch_id])->get()->keyBy('branch_id');

        return [
            'toShop' => $shops[$transfer->to_branch_id] ?? 'the receiving shop',
            'fromShop' => $shops[$transfer->from_branch_id] ?? 'the sending shop',
            'transfer' => (string) $transfer->getAttribute('relay'),
            'transferLines' => count($lines),
            'receipt' => $receipt === null ? 'notRelayed' : self::leg([$receipt, ...$receiptLines], $status->get($transfer->from_branch_id)),
            'toLastPullAt' => $status->get($transfer->to_branch_id)?->last_pull_at?->toIso8601ZuluString(),
            'fromLastPullAt' => $status->get($transfer->from_branch_id)?->last_pull_at?->toIso8601ZuluString(),
        ];
    }

    /** @param non-empty-list<Model> $rows */
    private static function leg(array $rows, ?SyncBranchStatus $status): string
    {
        $versions = array_map(fn ($r) => (int) $r->getAttribute('hub_version'), $rows);
        $lowest = min($versions);
        $highest = max($versions);

        return match (true) {
            $lowest <= 0 || $status === null => 'waiting',
            $highest <= (int) $status->last_pull_since => 'stored',
            $highest <= (int) $status->last_pull_version => 'sent',
            default => 'waiting',
        };
    }
}
