<?php

namespace App\Domain\Demo\Builders;

use App\Domain\Demo\Catalogue\DemoProducts;
use App\Domain\Demo\Support\DemoBusiness;
use App\Domain\Demo\Support\DemoPush;
use App\Domain\Demo\Support\DemoStaff;
use App\Domain\Demo\Support\StockBook;
use App\Domain\Reporting\Demo\DemoShop;

/**
 * Stock moved between the first two shops of a business: one transfer received a day later with a variance (a can
 * of drink missing, a bottle broken in the van), one dispatched and still on its way, and one just requested back.
 * A business with one shop has none.
 */
final class TransferBuilder
{
    public function handle(DemoBusiness $b, DemoPush $push, StockBook $book): void
    {
        if (count($b->shops) < 2) {
            return;
        }

        [$first, $second] = [$b->shops[0]['shop'], $b->shops[1]['shop']];
        $this->transfer($b, $push, $book, 1, $first, $second, 12, 'received');
        $this->transfer($b, $push, $book, 2, $first, $second, 1, 'dispatched');
        $this->transfer($b, $push, $book, 3, $second, $first, 0, 'requested');
    }

    private function transfer(DemoBusiness $b, DemoPush $push, StockBook $book, int $number, DemoShop $from, DemoShop $to, int $daysAgo, string $status): void
    {
        $rng = $b->rng("transfer|{$number}");
        $keys = array_keys(array_filter(DemoProducts::all(), fn (array $p) => in_array($p['department'], ['drinks', 'alcohol', 'confectionery', 'grocery'], true)));
        $requested = $b->at($daysAgo, $status === 'requested' ? 9 : 8, 30);
        $requested = $requested > $b->now ? $b->now->subMinutes(30) : $requested;
        $dispatched = $requested->addHours(2);
        $received = $dispatched->addHours(20);
        $transferId = $from->id("transfer|{$number}");
        $receiptId = $to->id("transfer-receipt|{$number}");
        $sender = DemoStaff::manager($from);
        $receiver = DemoStaff::manager($to);
        $lines = [];
        $dispatchedCost = 0;
        $receivedCost = 0;

        foreach ($rng->pickArrayKeys($keys, 6) as $i => $index) {
            $p = DemoProducts::get($keys[$index]);
            $qty = $rng->getInt(1, 2) * min(12, (int) $p['case']);
            $got = $status === 'received' && $i === 1 ? $qty - 1 : $qty;
            $lineId = $from->id("transfer|{$number}|{$i}");
            $lines[] = [$p, $qty, $got, $lineId];
            $dispatchedCost += $status === 'requested' ? 0 : $qty * $p['cost'];
            $receivedCost += $got * $p['cost'];

            $push->add($from, 'StockTransferLine', $lineId, [
                'transferId' => $transferId, 'productId' => $b->id("product|{$p['key']}"), 'productName' => $p['name'], 'qtyRequested' => $qty,
                'qtyDispatched' => $status === 'requested' ? 0 : $qty, 'unitCost' => $p['cost'] / 100, 'branchId' => $from->branchId,
            ], $status === 'requested' ? $requested : $dispatched, $requested);

            if ($status !== 'requested') {
                $book->move($from, $p['key'], 'transfer', -$qty, $dispatched, $p['cost'], 'StockTransfer', $transferId, $lineId, $sender, null, "Sent to {$to->branchCode}");
            }
        }

        $push->add($from, 'StockTransfer', $transferId, [
            'reference' => sprintf('TR-%s-%04d', $from->branchCode, $number), 'fromBranchId' => $from->branchId, 'toBranchId' => $to->branchId,
            'status' => $status, 'requestedAt' => DemoBusiness::iso($requested), 'requestedByUserId' => $sender,
            'dispatchedAt' => $status === 'requested' ? null : DemoBusiness::iso($dispatched), 'dispatchedByUserId' => $status === 'requested' ? '' : $sender,
            'dispatchedCost' => $dispatchedCost / 100, 'isReturn' => false, 'returnOfTransferId' => null,
            'note' => match ($status) {
                'requested' => 'Running low on soft drinks before the weekend', 'dispatched' => 'Sent with the afternoon van', default => 'Weekly top-up'
            },
            'lineCount' => count($lines), 'branchId' => $from->branchId,
        ], $status === 'received' ? $received : ($status === 'requested' ? $requested : $dispatched), $requested);

        if ($status !== 'received') {
            return;
        }

        foreach ($lines as $i => [$p, $qty, $got, $lineId]) {
            $receiptLineId = $to->id("transfer-receipt|{$number}|{$i}");
            $push->add($to, 'StockTransferReceiptLine', $receiptLineId, [
                'receiptId' => $receiptId, 'transferId' => $transferId, 'transferLineId' => $lineId, 'productId' => $b->id("product|{$p['key']}"),
                'qtyDispatched' => $qty, 'qtyReceived' => $got, 'qtyVariance' => $got - $qty, 'unitCost' => $p['cost'] / 100, 'branchId' => $to->branchId,
            ], $received);
            $book->move($to, $p['key'], 'transfer', $got, $received, $p['cost'], 'StockTransferReceipt', $receiptId, $receiptLineId, $receiver, null, "Received from {$from->branchCode}");
        }

        $push->add($to, 'StockTransferReceipt', $receiptId, [
            'transferId' => $transferId, 'status' => 'received', 'receivedAt' => DemoBusiness::iso($received), 'receivedByUserId' => $receiver,
            'receivedCost' => $receivedCost / 100, 'varianceCost' => ($dispatchedCost - $receivedCost) / 100, 'closedAt' => null,
            'note' => 'One unit missing from the crate, driver informed', 'branchId' => $to->branchId,
        ], $received);
    }
}
