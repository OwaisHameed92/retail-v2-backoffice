<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;

/**
 * Admin screens reach every company's invoices and payments, so route ids are looked up with the documented
 * `withoutCompanyScope()` escape hatch (never route model binding).
 */
trait FindsBillingRecords
{
    protected function findInvoice(string $id): Invoice
    {
        return Invoice::withoutCompanyScope()->with('company')->findOrFail($id);
    }

    protected function findPayment(string $id): Payment
    {
        return Payment::withoutCompanyScope()->with('company')->findOrFail($id);
    }
}
