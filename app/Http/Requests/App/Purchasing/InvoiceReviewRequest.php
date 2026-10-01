<?php

namespace App\Http\Requests\App\Purchasing;

use App\Domain\Purchasing\Invoices\InvoiceDraft;

/**
 * The invoice review form (module 6.5): the header, the lines and what the user pinned (supplier, products, order
 * or delivery). Money is pounds ex VAT (prices 4 dp, totals 2 dp); ids are checked against the business by
 * SaveInvoiceReview and InvoiceMatcher.
 */
class InvoiceReviewRequest extends InvoiceImportRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $money = ['nullable', 'numeric', 'min:-9999999', 'max:9999999'];

        return [
            'documentType' => ['required', 'in:invoice,deliveryNote'],
            'supplierName' => ['nullable', 'string', 'max:190'],
            'supplierVatNumber' => ['nullable', 'string', 'max:30'],
            'supplierId' => ['nullable', 'string', 'max:64'],
            'supplierPinned' => ['boolean'],
            'invoiceNumber' => ['nullable', 'string', 'max:60'],
            'invoiceDate' => ['nullable', 'date_format:Y-m-d'],
            'orderReference' => ['nullable', 'string', 'max:60'],
            'purchaseOrderId' => ['nullable', 'string', 'max:64'],
            'goodsReceiptId' => ['nullable', 'string', 'max:64'],
            'documentPinned' => ['boolean'],
            'netTotal' => [...$money, 'decimal:0,2'],
            'vatTotal' => [...$money, 'decimal:0,2'],
            'grossTotal' => [...$money, 'decimal:0,2'],
            'lines' => ['present', 'array', 'max:'.InvoiceDraft::MAX_LINES],
            'lines.*.description' => ['required', 'string', 'max:190'],
            'lines.*.barcode' => ['nullable', 'string', 'max:40'],
            'lines.*.supplierCode' => ['nullable', 'string', 'max:60'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000', 'decimal:0,4'],
            'lines.*.packSize' => ['required', 'integer', 'min:1', 'max:10000'],
            'lines.*.unitPrice' => ['nullable', 'numeric', 'min:0', 'max:9999999', 'decimal:0,4'],
            'lines.*.vatRate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'lines.*.lineNet' => [...$money, 'decimal:0,2'],
            'lines.*.productId' => ['nullable', 'string', 'max:64'],
            'lines.*.pinned' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.*.description.required' => 'Enter what the line is.',
            'lines.*.quantity.gt' => 'Enter a quantity above 0.',
            'lines.*.unitPrice.decimal' => 'Enter the price with up to 4 decimal places.',
        ];
    }
}
