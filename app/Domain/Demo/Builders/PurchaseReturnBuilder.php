<?php

namespace App\Domain\Demo\Builders;

use App\Domain\Demo\Catalogue\DemoPeople;
use App\Domain\Demo\Catalogue\DemoProducts;
use App\Domain\Demo\Support\DemoBusiness;
use App\Domain\Demo\Support\DemoJournal;
use App\Domain\Demo\Support\DemoPush;
use App\Domain\Demo\Support\DemoStaff;
use App\Domain\Demo\Support\StockBook;
use App\Domain\Reporting\Demo\DemoCatalogue;
use App\Domain\Reporting\Demo\DemoShop;

/**
 * Goods sent back to suppliers, per shop: chilled lines past their date returned to Booker and credited (with the
 * supplier's credit note), and damaged drinks returned to Bestway, sent and waiting for the credit.
 */
final class PurchaseReturnBuilder
{
    public function handle(DemoBusiness $b, DemoPush $push, DemoJournal $journal, StockBook $book): void
    {
        foreach ($b->shops as ['shop' => $shop]) {
            $this->return($b, $push, $journal, $book, $shop, 1, 'booker', ['meat', 'food2go', 'dairy'], 'expired', 10, true);
            $this->return($b, $push, $journal, $book, $shop, 2, 'bestway', ['softdrinks', 'water', 'cleaning'], 'damaged', 2, false);
        }
    }

    /**
     * @param  list<string>  $categories
     */
    private function return(DemoBusiness $b, DemoPush $push, DemoJournal $journal, StockBook $book, DemoShop $shop, int $number, string $supplier, array $categories, string $reason, int $daysAgo, bool $credited): void
    {
        $rng = $b->rng("return|{$shop->branchId}|{$number}");
        $keys = array_keys(array_filter(DemoProducts::all(), fn (array $p) => in_array($p['category'], $categories, true)));
        $at = $b->at($daysAgo, 11, 15);
        $returnId = $shop->id("return|{$number}");
        $creditId = $shop->id("return-credit|{$number}");
        $manager = DemoStaff::manager($shop);
        [$net, $vat] = [0, 0];

        foreach ($rng->pickArrayKeys($keys, 3) as $i => $index) {
            $p = DemoProducts::get($keys[$index]);
            $qty = $rng->getInt(1, 4);
            $lineNet = $qty * $p['cost'];
            $lineVat = (int) round($lineNet * DemoCatalogue::VAT[$p['vat']] / 100);
            [$net, $vat] = [$net + $lineNet, $vat + $lineVat];
            $lineId = $shop->id("return|{$number}|{$i}");
            $push->add($shop, 'PurchaseReturnLine', $lineId, [
                'purchaseReturnId' => $returnId, 'productId' => $b->id("product|{$p['key']}"), 'productName' => $p['name'], 'qty' => $qty,
                'unitCost' => $p['cost'] / 100, 'vatRateId' => $b->id("vat|{$p['vat']}"), 'vatPercentage' => DemoCatalogue::VAT[$p['vat']],
                'reason' => $reason, 'fromStock' => true, 'goodsReceiptLineId' => null, 'lineNet' => $lineNet / 100, 'lineVat' => $lineVat / 100,
                'note' => $reason === 'expired' ? 'Short-dated on delivery, past date before sold' : 'Split shrink-wrap, cans dented',
            ], $at);
            $book->move($shop, $p['key'], 'supplierReturn', -$qty, $at, $p['cost'], 'PurchaseReturn', $returnId, $lineId, $manager, null, 'Returned to '.DemoPeople::SUPPLIERS[$supplier][0]);
        }

        $creditAt = $at->addDays(4);
        $creditNumber = $credited ? 'CN-'.DemoPeople::SUPPLIERS[$supplier][1].'-R'.(abs(crc32($creditId)) % 100000) : '';
        $push->add($shop, 'PurchaseReturn', $returnId, [
            'supplierId' => $b->id("supplier|{$supplier}"), 'supplierName' => DemoPeople::SUPPLIERS[$supplier][0], 'number' => $number,
            'reference' => "RTN-{$shop->branchCode}-".str_pad((string) $number, 4, '0', STR_PAD_LEFT), 'status' => $credited ? 'credited' : 'sent',
            'returnDate' => $at->toDateString(), 'goodsReceiptId' => null, 'purchaseOrderId' => null, 'netAmount' => $net / 100, 'vatAmount' => $vat / 100,
            'grossAmount' => ($net + $vat) / 100, 'createdByUserId' => $manager, 'sentAt' => DemoBusiness::iso($at->addHour()), 'sentByUserId' => $manager,
            'creditNoteNumber' => $creditNumber, 'creditDate' => $credited ? $creditAt->toDateString() : null,
            'creditedAt' => $credited ? DemoBusiness::iso($creditAt) : null, 'supplierCreditNoteId' => $credited ? $creditId : null,
            'cancelledAt' => null, 'note' => $credited ? 'Collected by the Booker driver' : 'Waiting for Bestway to collect and credit', 'branchId' => $shop->branchId,
        ], $credited ? $creditAt : $at->addHour(), $at);

        if (! $credited) {
            return;
        }

        $push->add($shop, 'SupplierCreditNote', $creditId, [
            'supplierId' => $b->id("supplier|{$supplier}"), 'supplierName' => DemoPeople::SUPPLIERS[$supplier][0], 'creditNoteNumber' => $creditNumber,
            'creditDate' => $creditAt->toDateString(), 'reason' => 'Return '.$number.': out-of-date chilled goods', 'linkedInvoiceId' => null,
            'netAmount' => $net / 100, 'vatAmount' => $vat / 100, 'grossAmount' => ($net + $vat) / 100, 'appliedAmount' => 0,
            'balance' => ($net + $vat) / 100, 'notes' => null, 'branchId' => $shop->branchId,
        ], $creditAt);
        $journal->post($shop, "return-credit|{$creditId}", $creditAt, 'SupplierCreditNote', $creditId, 'Credit for returned goods', [
            ['2100', $net + $vat, 0], ['1001', 0, $net], ['2201', 0, $vat],
        ], $manager);
    }
}
