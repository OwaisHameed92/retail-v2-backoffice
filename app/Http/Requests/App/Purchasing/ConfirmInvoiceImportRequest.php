<?php

namespace App\Http\Requests\App\Purchasing;

/**
 * Confirming a reviewed invoice (module 6.5): optionally a head-office order (draft or sent) and the cost price
 * changes chosen from the preview; `acknowledged` when the checks found figures to look at. Who may order or change
 * costs is checked by ConfirmInvoiceImport (403).
 */
class ConfirmInvoiceImportRequest extends InvoiceImportRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'order' => ['nullable', 'in:none,draft,sent'],
            'costUpdates' => ['nullable', 'array', 'max:300'],
            'costUpdates.*' => ['string', 'max:64', 'distinct'],
            'acknowledged' => ['nullable', 'boolean'],
        ];
    }
}
