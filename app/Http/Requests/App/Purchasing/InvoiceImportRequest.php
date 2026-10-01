<?php

namespace App\Http\Requests\App\Purchasing;

use App\Domain\Purchasing\Invoices\InvoiceImportAccess;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Any change to an invoice import (module 6.5). Route: `company.can:purchasing.manage`; the company's plan must have
 * `assist_invoice_scan` (403 otherwise). Used on its own for retry and discard; the other requests extend it.
 */
class InvoiceImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return InvoiceImportAccess::inPlan(app(CurrentCompany::class)->require());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
