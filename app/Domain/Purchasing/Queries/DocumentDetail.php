<?php

namespace App\Domain\Purchasing\Queries;

use App\Domain\Purchasing\Support\PurchasingNames;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\GoodsReceipt;
use App\Domain\TillData\Models\GoodsReceiptLine;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Domain\TillData\Models\PurchaseReturn;
use App\Domain\TillData\Models\PurchaseReturnLine;
use App\Domain\TillData\Models\SupplierCreditNote;
use App\Domain\TillData\Models\SupplierCreditNoteLine;
use App\Domain\TillData\Models\SupplierInvoice;
use App\Domain\TillData\Models\SupplierInvoiceLine;
use Illuminate\Database\Eloquent\Model;

/**
 * One delivery (GRN), supplier invoice, credit note or purchase return (module 5.2), read only: the shop's figures
 * exactly as its till stored them, its lines and the documents linked to it. A one-shop user only reaches their own
 * shop's (the controller checks the shop).
 */
final class DocumentDetail
{
    /** @var array<string, class-string<Model>> */
    public const MODELS = [
        'deliveries' => GoodsReceipt::class,
        'invoices' => SupplierInvoice::class,
        'credit-notes' => SupplierCreditNote::class,
        'returns' => PurchaseReturn::class,
    ];

    /** @return array<string, mixed> */
    public static function for(string $kind, Model $doc): array
    {
        return match ($kind) {
            'deliveries' => self::delivery($doc instanceof GoodsReceipt ? $doc : abort(404)),
            'invoices' => self::invoice($doc instanceof SupplierInvoice ? $doc : abort(404)),
            'credit-notes' => self::creditNote($doc instanceof SupplierCreditNote ? $doc : abort(404)),
            'returns' => self::purchaseReturn($doc instanceof PurchaseReturn ? $doc : abort(404)),
            default => abort(404),
        };
    }

    /** @return array<string, mixed> */
    private static function delivery(GoodsReceipt $r): array
    {
        $lines = GoodsReceiptLine::query()->where('goods_receipt_id', $r->id)->orderBy('created_at')->orderBy('id')->get()->all();
        $names = PurchasingNames::for([$r, ...$lines]);
        $order = $r->purchase_order_id !== null ? PurchaseOrder::query()->find($r->purchase_order_id) : null;
        $invoices = SupplierInvoice::query()->where('goods_receipt_id', $r->id)->get();

        return self::shape('deliveries', $r, $names, $r->delivery_note_number ?: 'No delivery note', $r->status?->value, $r->received_date->format('Y-m-d'), [
            ['label' => 'Received', 'value' => $r->received_date->format('Y-m-d'), 'format' => 'date'],
            ['label' => 'Posted', 'value' => $r->posted_at?->toIso8601ZuluString(), 'format' => 'datetime'],
            ['label' => 'Cancelled', 'value' => $r->cancelled_at?->toIso8601ZuluString(), 'format' => 'datetime'],
            ['label' => 'Cancel reason', 'value' => $r->cancel_reason ?: null, 'format' => 'text'],
        ], ['expected' => 'Expected', 'received' => 'Received', 'damaged' => 'Damaged', 'unitCost' => 'Unit cost', 'net' => 'Net'],
            array_map(fn (GoodsReceiptLine $l) => [
                'id' => $l->id, 'product' => $names->product($l->product_id), 'note' => $l->note ?: null,
                'flag' => $l->is_short_or_over ? 'Short or over' : null,
                'expected' => $l->expected_qty, 'received' => $l->received_qty, 'damaged' => $l->damaged_qty ?? '0.0000',
                'unitCost' => $l->unit_cost, 'net' => Money::mul($l->received_qty ?? '0', $l->unit_cost ?? '0'),
            ], $lines),
            ['net' => $r->net_amount, 'vat' => $r->vat_amount, 'gross' => $r->gross_amount],
            [
                ...($order === null ? [] : [['kind' => 'orders', 'id' => $order->id, 'label' => 'Purchase order', 'reference' => $order->reference ?: $order->order_no, 'status' => $order->status?->value]]),
                ...$invoices->map(fn (SupplierInvoice $i) => ['kind' => 'invoices', 'id' => $i->id, 'label' => 'Invoice', 'reference' => $i->invoice_number, 'status' => $i->status?->value])->all(),
            ], $r->note);
    }

    /** @return array<string, mixed> */
    private static function invoice(SupplierInvoice $i): array
    {
        $lines = SupplierInvoiceLine::query()->where('supplier_invoice_id', $i->id)->orderBy('created_at')->orderBy('id')->get()->all();
        $names = PurchasingNames::for([$i, ...$lines]);
        $grn = $i->goods_receipt_id !== null ? GoodsReceipt::query()->find($i->goods_receipt_id) : null;
        $credits = SupplierCreditNote::query()->where('linked_invoice_id', $i->id)->get();

        return self::shape('invoices', $i, $names, $i->invoice_number ?: 'No invoice number', $i->status?->value, $i->invoice_date->format('Y-m-d'), [
            ['label' => 'Invoice date', 'value' => $i->invoice_date->format('Y-m-d'), 'format' => 'date'],
            ['label' => 'Due', 'value' => $i->due_date->format('Y-m-d'), 'format' => 'date'],
            ['label' => 'Paid', 'value' => $i->amount_paid, 'format' => 'money'],
            ['label' => 'Balance', 'value' => $i->balance, 'format' => 'money'],
            ['label' => 'Approved', 'value' => $i->approved_at?->toIso8601ZuluString(), 'format' => 'datetime'],
            ['label' => 'Disputed', 'value' => $i->dispute_reason ?: null, 'format' => 'text'],
        ], ['qty' => 'Qty', 'unitCost' => 'Unit cost', 'net' => 'Net', 'vat' => 'VAT'],
            array_map(fn (SupplierInvoiceLine $l) => [
                'id' => $l->id, 'product' => $names->product($l->product_id, $l->description), 'note' => $l->product_id !== null ? ($l->description ?: null) : null,
                'flag' => $l->has_qty_variance ? 'Quantity differs from the delivery' : ($l->has_price_variance ? 'Price differs from the order' : null),
                'qty' => $l->qty, 'unitCost' => $l->unit_cost, 'net' => $l->line_net, 'vat' => $l->line_vat,
            ], $lines),
            ['net' => $i->net_amount, 'vat' => $i->vat_amount, 'gross' => $i->gross_amount],
            [
                ...($grn === null ? [] : [['kind' => 'deliveries', 'id' => $grn->id, 'label' => 'Delivery', 'reference' => $grn->delivery_note_number ?: 'No delivery note', 'status' => $grn->status?->value]]),
                ...$credits->map(fn (SupplierCreditNote $c) => ['kind' => 'credit-notes', 'id' => $c->id, 'label' => 'Credit note', 'reference' => $c->credit_note_number, 'status' => null])->all(),
            ], $i->notes);
    }

    /** @return array<string, mixed> */
    private static function creditNote(SupplierCreditNote $c): array
    {
        $lines = SupplierCreditNoteLine::query()->where('supplier_credit_note_id', $c->id)->orderBy('created_at')->orderBy('id')->get()->all();
        $names = PurchasingNames::for([$c, ...$lines]);
        $invoice = $c->linked_invoice_id !== null ? SupplierInvoice::query()->find($c->linked_invoice_id) : null;
        $return = PurchaseReturn::query()->where('supplier_credit_note_id', $c->id)->first();

        return self::shape('credit-notes', $c, $names, $c->credit_note_number ?: 'No credit note number', null, $c->credit_date->format('Y-m-d'), [
            ['label' => 'Credit date', 'value' => $c->credit_date->format('Y-m-d'), 'format' => 'date'],
            ['label' => 'Reason', 'value' => $c->reason ?: null, 'format' => 'text'],
            ['label' => 'Used', 'value' => $c->applied_amount, 'format' => 'money'],
            ['label' => 'Not yet used', 'value' => $c->balance, 'format' => 'money'],
        ], ['qty' => 'Qty', 'unitCost' => 'Unit cost', 'net' => 'Net', 'vat' => 'VAT'],
            array_map(fn (SupplierCreditNoteLine $l) => [
                'id' => $l->id, 'product' => $names->product($l->product_id, $l->description), 'note' => null, 'flag' => null,
                'qty' => $l->qty, 'unitCost' => $l->unit_cost, 'net' => $l->line_net, 'vat' => $l->line_vat,
            ], $lines),
            ['net' => $c->net_amount, 'vat' => $c->vat_amount, 'gross' => $c->gross_amount],
            [
                ...($invoice === null ? [] : [['kind' => 'invoices', 'id' => $invoice->id, 'label' => 'Invoice', 'reference' => $invoice->invoice_number, 'status' => $invoice->status?->value]]),
                ...($return === null ? [] : [['kind' => 'returns', 'id' => $return->id, 'label' => 'Purchase return', 'reference' => $return->reference, 'status' => $return->status?->value]]),
            ], $c->notes);
    }

    /** @return array<string, mixed> */
    private static function purchaseReturn(PurchaseReturn $r): array
    {
        $lines = PurchaseReturnLine::query()->where('purchase_return_id', $r->id)->orderBy('created_at')->orderBy('id')->get()->all();
        $names = PurchasingNames::for([$r, ...$lines]);
        $grn = $r->goods_receipt_id !== null ? GoodsReceipt::query()->find($r->goods_receipt_id) : null;
        $credit = $r->supplier_credit_note_id !== null ? SupplierCreditNote::query()->find($r->supplier_credit_note_id) : null;

        return self::shape('returns', $r, $names, $r->reference ?: sprintf('PR-%05d', $r->number), $r->status?->value, $r->return_date->format('Y-m-d'), [
            ['label' => 'Return date', 'value' => $r->return_date->format('Y-m-d'), 'format' => 'date'],
            ['label' => 'Sent', 'value' => $r->sent_at?->toIso8601ZuluString(), 'format' => 'datetime'],
            ['label' => 'Credit note', 'value' => $r->credit_note_number ?: null, 'format' => 'text'],
            ['label' => 'Credited', 'value' => $r->credited_at?->toIso8601ZuluString(), 'format' => 'datetime'],
            ['label' => 'Cancelled', 'value' => $r->cancelled_at?->toIso8601ZuluString(), 'format' => 'datetime'],
        ], ['reason' => 'Reason', 'qty' => 'Qty', 'unitCost' => 'Unit cost', 'net' => 'Net', 'vat' => 'VAT'],
            array_map(fn (PurchaseReturnLine $l) => [
                'id' => $l->id, 'product' => $names->product($l->product_id, $l->product_name), 'note' => $l->note ?: null,
                'flag' => $l->from_stock ? null : 'Not taken from stock', 'reason' => $l->reason?->value,
                'qty' => $l->qty, 'unitCost' => $l->unit_cost, 'net' => $l->line_net, 'vat' => $l->line_vat,
            ], $lines),
            ['net' => $r->net_amount, 'vat' => $r->vat_amount, 'gross' => $r->gross_amount],
            [
                ...($grn === null ? [] : [['kind' => 'deliveries', 'id' => $grn->id, 'label' => 'Delivery', 'reference' => $grn->delivery_note_number ?: 'No delivery note', 'status' => $grn->status?->value]]),
                ...($credit === null ? [] : [['kind' => 'credit-notes', 'id' => $credit->id, 'label' => 'Credit note', 'reference' => $credit->credit_note_number, 'status' => null]]),
            ], $r->note);
    }

    /**
     * @param  list<array{label: string, value: string|null, format: string}>  $facts
     * @param  array<string, string>  $columns
     * @param  list<array<string, mixed>>  $lines
     * @param  array{net: string|null, vat: string|null, gross: string|null}  $totals
     * @param  list<array<string, mixed>>  $links
     * @return array<string, mixed>
     */
    private static function shape(string $kind, Model $doc, PurchasingNames $names, string $reference, ?string $status, ?string $date, array $facts, array $columns, array $lines, array $totals, array $links, ?string $note): array
    {
        return [
            'kind' => $kind,
            'document' => [
                'id' => $doc->getKey(), 'reference' => $reference, 'status' => $status, 'date' => $date,
                'shop' => $names->shop($doc->getAttribute('branch_id')),
                'supplier' => $names->supplier($doc->getAttribute('supplier_id'), $doc->getAttribute('supplier_name')),
                'supplierId' => $doc->getAttribute('supplier_id'), 'note' => $note ?: null,
            ],
            'facts' => array_values(array_filter($facts, fn (array $f) => $f['value'] !== null)),
            'columns' => array_map(fn (string $key, string $label) => ['key' => $key, 'label' => $label], array_keys($columns), $columns),
            'lines' => $lines,
            'totals' => $totals,
            'links' => $links,
        ];
    }
}
