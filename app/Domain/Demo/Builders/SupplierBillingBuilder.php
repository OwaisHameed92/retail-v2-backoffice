<?php

namespace App\Domain\Demo\Builders;

use App\Domain\Demo\Catalogue\DemoPeople;
use App\Domain\Demo\Catalogue\DemoProducts;
use App\Domain\Demo\Support\DemoBusiness;
use App\Domain\Demo\Support\DemoJournal;
use App\Domain\Demo\Support\DemoPush;
use App\Domain\Demo\Support\DemoStaff;
use App\Domain\Reporting\Demo\DemoCatalogue;
use App\Domain\Reporting\Demo\DemoShop;
use Carbon\CarbonImmutable;

/**
 * The supplier side of every delivery: an invoice per goods receipt (matched, approved, paid, part paid, or one
 * disputed over a price rise), a credit note for goods damaged on delivery, supplier payments with their allocations
 * (bank transfer, direct debit for the dairy, cheque for Parfetts), and the journals (stock and input VAT against
 * trade creditors, creditors against the bank).
 *
 * @phpstan-import-type Delivery from PurchaseOrderBuilder
 */
final class SupplierBillingBuilder
{
    /**
     * @param  list<Delivery>  $deliveries
     */
    public function handle(DemoBusiness $b, DemoPush $push, DemoJournal $journal, array $deliveries): void
    {
        /** @var array<string, list<array{id: string, gross: int, credit: string|null, creditGross: int, at: CarbonImmutable, shop: DemoShop, part: bool}>> $payable */
        $payable = [];

        foreach ($deliveries as $d) {
            $shop = $d['shop'];
            $invoiceId = $shop->id("invoice|{$d['grnId']}");
            $manager = DemoStaff::manager($shop);
            $prefix = DemoPeople::SUPPLIERS[$d['supplier']][1];
            [$net, $vat, $creditNet, $creditVat] = [0, 0, 0, 0];
            $creditId = $shop->id("credit|{$d['grnId']}");
            $hasCredit = false;

            foreach ($d['lines'] as $i => $l) {
                if ($l['qty'] === 0) {
                    continue;
                }

                // A disputed invoice charges 6% more on its first line than the order said.
                $cost = $d['disputed'] && $i === 0 ? (int) round($l['cost'] * 1.06) : $l['cost'];
                $lineNet = $l['qty'] * $cost;
                $lineVat = (int) round($lineNet * DemoCatalogue::VAT[$l['vat']] / 100);
                [$net, $vat] = [$net + $lineNet, $vat + $lineVat];
                $push->add($shop, 'SupplierInvoiceLine', $shop->id("invoice|{$d['grnId']}|{$i}"), [
                    'supplierInvoiceId' => $invoiceId, 'productId' => $b->id("product|{$l['key']}"), 'description' => DemoProducts::get($l['key'])['name'],
                    'qty' => $l['qty'], 'unitCost' => $cost / 100, 'lineNet' => $lineNet / 100, 'lineVat' => $lineVat / 100, 'vatRateId' => $b->id("vat|{$l['vat']}"),
                    'expectedQty' => $l['qty'], 'expectedUnitCost' => $l['cost'] / 100, 'hasQtyVariance' => false, 'hasPriceVariance' => $cost !== $l['cost'],
                ], $d['at']->addHours(2));

                if ($l['damaged'] > 0) {
                    $hasCredit = true;
                    $cNet = $l['damaged'] * $l['cost'];
                    $cVat = (int) round($cNet * DemoCatalogue::VAT[$l['vat']] / 100);
                    [$creditNet, $creditVat] = [$creditNet + $cNet, $creditVat + $cVat];
                    $push->add($shop, 'SupplierCreditNoteLine', $shop->id("credit|{$d['grnId']}|{$i}"), [
                        'supplierCreditNoteId' => $creditId, 'productId' => $b->id("product|{$l['key']}"), 'description' => DemoProducts::get($l['key'])['name'].' (damaged)',
                        'qty' => $l['damaged'], 'unitCost' => $l['cost'] / 100, 'lineNet' => $cNet / 100, 'lineVat' => $cVat / 100,
                    ], $d['at']->addDays(2));
                }
            }

            $gross = $net + $vat;
            $creditGross = $creditNet + $creditVat;
            $paid = $d['paid'] ? $gross : ($d['partPaid'] ? intdiv($gross, 2) : 0);
            $status = match (true) {
                $d['disputed'] => 'disputed', $d['paid'] => 'paid', $d['partPaid'] => 'partPaid', $d['at'] < $b->now->subDays(3) => 'approved', default => 'matched'
            };
            $invoiceAt = $d['at']->addHours(2);

            $push->add($shop, 'SupplierInvoice', $invoiceId, [
                'supplierId' => $b->id("supplier|{$d['supplier']}"), 'goodsReceiptId' => $d['grnId'], 'supplierName' => DemoPeople::SUPPLIERS[$d['supplier']][0],
                'invoiceNumber' => $prefix.'-'.str_pad((string) (abs(crc32($invoiceId)) % 1000000), 6, '0', STR_PAD_LEFT),
                'invoiceDate' => $d['at']->toDateString(), 'dueDate' => $d['at']->addDays(DemoPeople::SUPPLIERS[$d['supplier']][7])->toDateString(),
                'status' => $status, 'netAmount' => $net / 100, 'vatAmount' => $vat / 100, 'grossAmount' => $gross / 100, 'amountPaid' => $paid / 100,
                'balance' => ($gross - $paid) / 100, 'varianceFlags' => $d['disputed'] ? 'price' : '', 'matchedAt' => DemoBusiness::iso($invoiceAt),
                'approvedAt' => in_array($status, ['approved', 'paid', 'partPaid'], true) ? DemoBusiness::iso($invoiceAt->addDay()) : null,
                'approvedByUserId' => in_array($status, ['approved', 'paid', 'partPaid'], true) ? $manager : null,
                'disputedAt' => $d['disputed'] ? DemoBusiness::iso($invoiceAt->addDay()) : null,
                'disputeReason' => $d['disputed'] ? 'Charged above the agreed promotional price; rep asked to credit the difference.' : null,
                'attachmentPath' => null, 'notes' => null, 'branchId' => $shop->branchId,
            ], $invoiceAt);
            $journal->post($shop, "invoice|{$invoiceId}", $invoiceAt, 'SupplierInvoice', $invoiceId, 'Invoice '.DemoPeople::SUPPLIERS[$d['supplier']][0], [
                ['1001', $net, 0], ['2201', $vat, 0], ['2100', 0, $gross],
            ], $manager);

            if ($hasCredit) {
                $creditAt = $d['at']->addDays(2);
                $push->add($shop, 'SupplierCreditNote', $creditId, [
                    'supplierId' => $b->id("supplier|{$d['supplier']}"), 'supplierName' => DemoPeople::SUPPLIERS[$d['supplier']][0],
                    'creditNoteNumber' => 'CN-'.$prefix.'-'.(abs(crc32($creditId)) % 100000), 'creditDate' => $creditAt->toDateString(),
                    'reason' => 'Goods damaged on delivery', 'linkedInvoiceId' => $invoiceId, 'netAmount' => $creditNet / 100, 'vatAmount' => $creditVat / 100,
                    'grossAmount' => $creditGross / 100, 'appliedAmount' => $d['paid'] ? $creditGross / 100 : 0,
                    'balance' => $d['paid'] ? 0 : $creditGross / 100, 'notes' => null, 'branchId' => $shop->branchId,
                ], $creditAt);
                $journal->post($shop, "credit|{$creditId}", $creditAt, 'SupplierCreditNote', $creditId, 'Credit note '.DemoPeople::SUPPLIERS[$d['supplier']][0], [
                    ['2100', $creditGross, 0], ['1001', 0, $creditNet], ['2201', 0, $creditVat],
                ], $manager);
            }

            if ($paid > 0) {
                $payable["{$shop->branchId}|{$d['supplier']}"][] = [
                    'id' => $invoiceId, 'gross' => $paid, 'credit' => $hasCredit && $d['paid'] ? $creditId : null,
                    'creditGross' => $hasCredit && $d['paid'] ? $creditGross : 0, 'at' => $d['at'], 'shop' => $shop, 'part' => $d['partPaid'],
                ];
            }
        }

        $this->payments($b, $push, $journal, $payable);
    }

    /**
     * One payment per supplier run: weekly for most, monthly for Parfetts (end-of-month terms).
     *
     * @param  array<string, list<array{id: string, gross: int, credit: string|null, creditGross: int, at: CarbonImmutable, shop: DemoShop, part: bool}>>  $payable
     */
    private function payments(DemoBusiness $b, DemoPush $push, DemoJournal $journal, array $payable): void
    {
        foreach ($payable as $group => $invoices) {
            [, $supplier] = explode('|', $group);
            $runs = [];

            foreach ($invoices as $invoice) {
                $due = $invoice['at']->addDays(DemoPeople::SUPPLIERS[$supplier][7]);
                $runs[$supplier === 'parfetts' ? $due->format('Y-m') : $due->format('o-W')][] = $invoice;
            }

            foreach ($runs as $run => $batch) {
                $shop = $batch[0]['shop'];
                $last = max(array_map(fn (array $i) => $i['at'], $batch));
                $at = $last->addDays(DemoPeople::SUPPLIERS[$supplier][7])->setTime(11, 0);
                $at = $at > $b->now ? $b->now->subHours(1) : $at;
                $amount = array_sum(array_column($batch, 'gross')) - array_sum(array_column($batch, 'creditGross'));
                $paymentId = $shop->id("payment|{$supplier}|{$run}");
                $method = match ($supplier) {
                    'dairy', 'news' => 'directDebit', 'parfetts' => 'cheque', default => 'bankTransfer'
                };

                foreach ($batch as $i => $invoice) {
                    $push->add($shop, 'SupplierPaymentAllocation', $shop->id("payment|{$supplier}|{$run}|{$i}"), [
                        'supplierPaymentId' => $paymentId, 'targetType' => 'invoice', 'targetId' => $invoice['id'], 'amount' => $invoice['gross'] / 100,
                        'allocatedAt' => DemoBusiness::iso($at), 'isReversed' => false, 'reversedAt' => null,
                    ], $at);

                    if ($invoice['credit'] !== null) {
                        $push->add($shop, 'SupplierPaymentAllocation', $shop->id("payment|{$supplier}|{$run}|{$i}|credit"), [
                            'supplierPaymentId' => $paymentId, 'targetType' => 'creditNote', 'targetId' => $invoice['credit'], 'amount' => $invoice['creditGross'] / 100,
                            'allocatedAt' => DemoBusiness::iso($at), 'isReversed' => false, 'reversedAt' => null,
                        ], $at);
                    }
                }

                $push->add($shop, 'SupplierPayment', $paymentId, [
                    'supplierId' => $b->id("supplier|{$supplier}"), 'supplierName' => DemoPeople::SUPPLIERS[$supplier][0], 'paymentDate' => $at->toDateString(),
                    'method' => $method, 'reference' => $method === 'cheque' ? 'CHQ '.(100200 + count($runs)) : strtoupper($supplier).' '.$run,
                    'amount' => $amount / 100, 'allocatedAmount' => $amount / 100, 'unallocated' => 0, 'notes' => null, 'isReversed' => false,
                    'reversedAt' => null, 'reversedByUserId' => null, 'branchId' => $shop->branchId,
                ], $at);
                $journal->post($shop, "payment|{$paymentId}", $at, 'SupplierPayment', $paymentId, 'Payment to '.DemoPeople::SUPPLIERS[$supplier][0], [
                    ['2100', $amount, 0], ['1200', 0, $amount],
                ], DemoStaff::manager($shop));
            }
        }
    }
}
