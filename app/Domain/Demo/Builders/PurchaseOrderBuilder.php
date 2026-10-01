<?php

namespace App\Domain\Demo\Builders;

use App\Domain\Demo\Catalogue\DemoPeople;
use App\Domain\Demo\Catalogue\DemoProducts;
use App\Domain\Demo\Support\DemoBusiness;
use App\Domain\Demo\Support\DemoPush;
use App\Domain\Demo\Support\DemoStaff;
use App\Domain\Demo\Support\StockBook;
use App\Domain\Reporting\Demo\DemoCatalogue;
use App\Domain\Reporting\Demo\DemoShop;
use Carbon\CarbonImmutable;
use Random\Randomizer;

/**
 * Each shop's orders and deliveries: Booker on Tuesdays, Bestway on Thursdays, Parfetts every other Friday and the
 * dairy three times a week. Past orders were delivered (goods receipt posted, some cases short, some damaged), one
 * was cancelled, the latest Bestway order came part-delivered; upcoming ones are sent, the next is a draft. Stock
 * movements go to the StockBook; the deliveries are returned for SupplierBillingBuilder.
 *
 * @phpstan-type Delivery array{shop: DemoShop, supplier: string, poId: string, grnId: string, at: CarbonImmutable, lines: list<array{key: string, lineId: string, qty: int, damaged: int, cost: int, vat: string}>, paid: bool, disputed: bool, partPaid: bool}
 */
final class PurchaseOrderBuilder
{
    /** Supplier => [ISO weekdays it delivers, lines per order (min, max)]. Parfetts: even ISO weeks only. */
    public const ROUNDS = ['booker' => [[2], 22, 34], 'bestway' => [[4], 16, 26], 'parfetts' => [[5], 12, 20], 'dairy' => [[1, 3, 5], 6, 12]];

    /** Shelf life in days of what a delivery brings, by category (others: 120). */
    public const SHELF_LIFE = ['milk' => 8, 'dairy' => 20, 'eggs' => 21, 'bread' => 5, 'food2go' => 3, 'meat' => 10, 'fruit' => 6, 'veg' => 7, 'bakery' => 3, 'frozenmeals' => 180, 'frozenveg' => 240, 'icecream' => 300];

    /**
     * @return list<Delivery>
     */
    public function handle(DemoBusiness $b, DemoPush $push, StockBook $book): array
    {
        $bySupplier = [];

        foreach (DemoProducts::all() as $key => $p) {
            $bySupplier[DemoPeople::supplierOf($p['category'])][] = $key;
        }

        $deliveries = [];

        foreach ($b->shops as $s => ['shop' => $shop]) {
            $orders = $this->schedule($b);
            $rng = $b->rng("purchasing|{$shop->branchId}");
            $lastBestway = max(array_keys(array_filter($orders, fn (array $o) => $o[0] === 'bestway' && $o[1] >= 1)) ?: [-1]);

            foreach ($orders as $n => [$supplier, $daysAgo]) {
                $state = match (true) {
                    $daysAgo < 0 && $n === array_key_last($orders) => 'draft',
                    $daysAgo < 0 || $b->at($daysAgo, 12) > $b->now => 'sent',
                    $n === 5 => 'cancelled',
                    $n === $lastBestway => 'partReceived',
                    default => 'received',
                };
                $delivery = $this->order($b, $push, $book, $shop, $rng, $supplier, $bySupplier[$supplier], $daysAgo, 1000 + $n + 1, $state);

                if ($delivery !== null) {
                    $deliveries[] = $delivery;
                }
            }
        }

        return $this->decideBills($b, $deliveries);
    }

    /**
     * Orders of the history window, oldest first: [supplier, days ago (negative = expected later)].
     *
     * @return list<array{0: string, 1: int}>
     */
    private function schedule(DemoBusiness $b): array
    {
        $orders = [];

        for ($d = $b->history; $d >= -4; $d--) {
            $day = CarbonImmutable::parse($b->date($d), 'UTC');

            foreach (self::ROUNDS as $supplier => [$weekdays]) {
                if (in_array($day->dayOfWeekIso, $weekdays, true) && ($supplier !== 'parfetts' || $day->isoWeek % 2 === 0)) {
                    $orders[] = [$supplier, $d];
                }
            }
        }

        return $orders;
    }

    /**
     * @param  list<string>  $range
     * @return Delivery|null
     */
    private function order(DemoBusiness $b, DemoPush $push, StockBook $book, DemoShop $shop, Randomizer $rng, string $supplier, array $range, int $daysAgo, int $number, string $state): ?array
    {
        $poId = $shop->id("po|{$number}");
        $deliverAt = $b->at($daysAgo, $supplier === 'dairy' ? 6 : 10, $rng->getInt(0, 50));
        $sentAt = $b->at($daysAgo + ($supplier === 'dairy' ? 1 : 2), 16, $rng->getInt(0, 59));
        $manager = DemoStaff::manager($shop);
        [, $min, $max] = self::ROUNDS[$supplier];
        $picked = $rng->pickArrayKeys($range, min(count($range), $rng->getInt($min, $max)));
        $lines = [];
        [$net, $vat] = [0, 0];

        foreach ($picked as $position => $index) {
            $key = $range[$index];
            $p = DemoProducts::get($key);
            $cases = $p['case'] >= 24 ? 1 : $rng->getInt(1, 3);
            $qty = $cases * (int) $p['case'];
            $lineId = $shop->id("po|{$number}|{$position}");
            $received = $state === 'partReceived' && $position % 4 === 1 ? 0 : (($rng->nextFloat() < 0.04 && $cases > 1) ? $qty - (int) $p['case'] : $qty);
            $lines[] = ['key' => $key, 'lineId' => $lineId, 'qty' => $qty, 'received' => $received, 'cost' => $p['cost'], 'vat' => $p['vat'], 'cases' => $cases];
            $net += $qty * $p['cost'];
            $vat += (int) round($qty * $p['cost'] * DemoCatalogue::VAT[$p['vat']] / 100);
        }

        $delivered = in_array($state, ['received', 'partReceived'], true);
        $push->add($shop, 'PurchaseOrder', $poId, [
            'supplierId' => $b->id("supplier|{$supplier}"), 'number' => $number, 'status' => $state === 'received' ? 'invoiced' : $state,
            'expectedDate' => $deliverAt->setTimezone('Europe/London')->toDateString(),
            'sentAt' => $state === 'draft' ? null : DemoBusiness::iso($sentAt), 'sentByUserId' => $state === 'draft' ? null : $manager,
            'cancelledAt' => $state === 'cancelled' ? DemoBusiness::iso($sentAt->addHours(18)) : null, 'cancelledByUserId' => $state === 'cancelled' ? $manager : null,
            'cancelReason' => $state === 'cancelled' ? 'Depot short of stock, order moved to next week' : null, 'notes' => null,
            'netTotal' => $net / 100, 'vatTotal' => $vat / 100, 'grossTotal' => ($net + $vat) / 100, 'discountAmount' => 0, 'discountReasonId' => null,
            'origin' => 'branch', 'orderNo' => null, 'branchCode' => $shop->branchCode, 'reference' => strtoupper(substr($supplier, 0, 3))."-{$shop->branchCode}-{$number}", 'branchId' => $shop->branchId,
        ], $state === 'draft' ? $b->now->subHours(2) : $sentAt, $sentAt->subHours(1));

        foreach ($lines as $position => $l) {
            $push->add($shop, 'PurchaseOrderLine', $l['lineId'], [
                'purchaseOrderId' => $poId, 'productId' => $b->id("product|{$l['key']}"), 'position' => $position + 1, 'orderedCases' => $l['cases'],
                'caseQtySnapshot' => intdiv($l['qty'], $l['cases']), 'orderedUnits' => $l['qty'], 'unitCostSnapshot' => $l['cost'] / 100,
                'receivedQty' => $delivered ? $l['received'] : 0, 'vatRateId' => $b->id("vat|{$l['vat']}"), 'vatPercentage' => DemoCatalogue::VAT[$l['vat']],
            ], $sentAt);
        }

        return $delivered ? $this->receive($b, $push, $book, $shop, $rng, $supplier, $poId, $number, $deliverAt, $lines) : null;
    }

    /**
     * @param  list<array{key: string, lineId: string, qty: int, received: int, cost: int, vat: string, cases: int}>  $lines
     * @return Delivery
     */
    private function receive(DemoBusiness $b, DemoPush $push, StockBook $book, DemoShop $shop, Randomizer $rng, string $supplier, string $poId, int $number, CarbonImmutable $at, array $lines): array
    {
        $grnId = $shop->id("grn|{$number}");
        $manager = DemoStaff::manager($shop);
        $damagedLine = $rng->nextFloat() < 0.25 ? $rng->getInt(0, count($lines) - 1) : -1;
        $out = [];
        [$net, $vat] = [0, 0];

        foreach ($lines as $i => $l) {
            $p = DemoProducts::get($l['key']);
            $damaged = $i === $damagedLine && $l['received'] > 0 ? min($l['received'], $rng->getInt(1, 2)) : 0;
            $shelf = self::SHELF_LIFE[$p['category']] ?? 120;
            $grnLineId = $shop->id("grn|{$number}|{$i}");
            $push->add($shop, 'GoodsReceiptLine', $grnLineId, [
                'goodsReceiptId' => $grnId, 'purchaseOrderLineId' => $l['lineId'], 'productId' => $b->id("product|{$l['key']}"), 'expectedProductId' => null,
                'expectedQty' => $l['qty'], 'receivedQty' => $l['received'], 'damagedQty' => $damaged, 'caseQty' => intdiv($l['qty'], $l['cases']),
                'unitCost' => $l['cost'] / 100, 'vatRateId' => $b->id("vat|{$l['vat']}"), 'vatPercentage' => DemoCatalogue::VAT[$l['vat']],
                'isShortOrOver' => $l['received'] !== $l['qty'],
                'note' => match (true) {
                    $damaged > 0 => ['Crushed outer', 'Leaking bottle', 'Split bag', 'Broken seal'][$i % 4], $l['received'] === 0 => 'Out of stock at depot', $l['received'] !== $l['qty'] => 'One case short', default => ''
                },
                'expiryDate' => $p['expiry'] ? $at->addDays($shelf)->toDateString() : null, 'batchNo' => $p['expiry'] ? 'L'.$at->format('ymd').($i % 10) : null,
            ], $at);

            $book->move($shop, $l['key'], 'received', $l['received'], $at, $l['cost'], 'GoodsReceipt', $grnId, $grnLineId, $manager);
            $book->move($shop, $l['key'], 'damaged', -$damaged, $at->addMinutes(20), $l['cost'], 'GoodsReceipt', $grnId, $grnLineId, $manager, null, 'Damaged on delivery');

            if ($l['received'] > 0) {
                $book->delivered($shop, $l['key'], $at, $grnId, $l['cost']);
            }

            $net += $l['received'] * $l['cost'];
            $vat += (int) round($l['received'] * $l['cost'] * DemoCatalogue::VAT[$l['vat']] / 100);
            $out[] = ['key' => $l['key'], 'lineId' => $grnLineId, 'qty' => $l['received'], 'damaged' => $damaged, 'cost' => $l['cost'], 'vat' => $l['vat']];
        }

        $push->add($shop, 'GoodsReceipt', $grnId, [
            'supplierId' => $b->id("supplier|{$supplier}"), 'supplierName' => DemoPeople::SUPPLIERS[$supplier][0], 'purchaseOrderId' => $poId,
            'deliveryNoteNumber' => 'DN'.str_pad((string) (abs(crc32($grnId)) % 10000000), 7, '0', STR_PAD_LEFT),
            'receivedDate' => $at->setTimezone('Europe/London')->toDateString(), 'status' => 'posted', 'receivedByUserId' => $manager,
            'netAmount' => $net / 100, 'vatAmount' => $vat / 100, 'grossAmount' => ($net + $vat) / 100, 'postedAt' => DemoBusiness::iso($at->addMinutes(25)),
            'cancelledAt' => null, 'cancelReason' => null, 'note' => $damagedLine >= 0 ? 'Driver signed for damages' : null, 'branchId' => $shop->branchId,
        ], $at->addMinutes(25), $at);

        return ['shop' => $shop, 'supplier' => $supplier, 'poId' => $poId, 'grnId' => $grnId, 'at' => $at, 'lines' => $out, 'paid' => false, 'disputed' => false, 'partPaid' => false];
    }

    /**
     * Which invoices are paid (due before today), disputed (the second newest Booker one per shop) or part paid
     * (the oldest unpaid Bestway one).
     *
     * @param  list<Delivery>  $deliveries
     * @return list<Delivery>
     */
    private function decideBills(DemoBusiness $b, array $deliveries): array
    {
        $today = CarbonImmutable::parse($b->today, 'UTC');

        foreach ($deliveries as $i => $d) {
            $due = $d['at']->addDays(DemoPeople::SUPPLIERS[$d['supplier']][7]);
            $deliveries[$i]['paid'] = $due < $today;
        }

        foreach ($b->shops as ['shop' => $shop]) {
            $mine = array_keys(array_filter($deliveries, fn (array $d) => $d['shop']->branchId === $shop->branchId && $d['supplier'] === 'booker'));
            $disputed = $mine[count($mine) - 2] ?? null;

            if ($disputed !== null) {
                $deliveries[$disputed]['disputed'] = true;
                $deliveries[$disputed]['paid'] = false;
            }

            $unpaid = array_keys(array_filter($deliveries, fn (array $d) => $d['shop']->branchId === $shop->branchId && $d['supplier'] === 'bestway' && ! $d['paid']));

            if ($unpaid !== []) {
                $deliveries[$unpaid[0]]['partPaid'] = true;
            }
        }

        return $deliveries;
    }
}
