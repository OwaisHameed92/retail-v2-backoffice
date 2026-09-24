<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Domain\Billing\Actions\ApplyCredit;
use App\Domain\Billing\Actions\GenerateInvoice;
use App\Domain\Billing\Actions\RecordPayment;
use App\Domain\Billing\Actions\UpdateBillingSettings;
use App\Domain\Billing\Data\TenantBilling;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Billing\BillingSettingsRequest;
use App\Http\Requests\Admin\Billing\CreateInvoiceRequest;
use App\Http\Requests\Admin\Billing\RecordPaymentRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Billing actions on one tenant (module 1.8), from the tenant's Billing tab, the invoice page and the payment
 * list. `billing.manage` (route middleware).
 */
class TenantBillingController extends Controller
{
    /** JSON preview for the "Create invoice" dialog: period, lines, totals, overlap. Writes nothing. */
    public function preview(CreateInvoiceRequest $request, Company $company, GenerateInvoice $generate): JsonResponse
    {
        return response()->json($generate->plan($company, $request->toNewInvoice(), CarbonImmutable::now())->toArray());
    }

    /** JSON: open invoices of the company, for the "Record payment" dialog. */
    public function openInvoices(Company $company): JsonResponse
    {
        return response()->json(['invoices' => TenantBilling::openInvoices($company)]);
    }

    public function createInvoice(CreateInvoiceRequest $request, Company $company, GenerateInvoice $generate): RedirectResponse
    {
        $invoice = $generate->handle($company, $request->toNewInvoice());

        return redirect()->route('admin.billing.invoices.show', $invoice->id)->with('success', $invoice->isDraft()
            ? 'Draft invoice created. Check it, then issue it.'
            : "{$invoice->number} issued".($invoice->sent_count > 0 ? ' and emailed with the PDF.' : '.'));
    }

    public function recordPayment(RecordPaymentRequest $request, Company $company, RecordPayment $record): RedirectResponse
    {
        $result = $record->handle($company, $request->toNewPayment());
        $parts = [BillingFormat::money($result->payment->amount).' recorded as '.$result->payment->number.'.'];

        if ($result->paidInvoices !== []) {
            $numbers = implode(', ', array_map(fn ($invoice) => $invoice->number, $result->paidInvoices));
            $parts[] = "{$numbers} paid".($result->licencesRenewed > 0 ? ', '.($result->licencesRenewed === 1 ? '1 licence' : "{$result->licencesRenewed} licences").' renewed.' : '.');
        }

        if (BillingFormat::money($result->credit) !== '£0.00') {
            $parts[] = BillingFormat::money($result->credit).' kept as credit.';
        }

        if ($result->unsuspended) {
            $parts[] = 'The suspension is lifted.';
        }

        return back()->with('success', implode(' ', $parts));
    }

    public function settings(BillingSettingsRequest $request, Company $company, UpdateBillingSettings $update): RedirectResponse
    {
        $update->handle($company, $request->toInput());

        return back()->with('success', 'Billing settings saved.');
    }

    public function applyCredit(Company $company, ApplyCredit $apply): RedirectResponse
    {
        $applied = $apply->handle($company);

        return back()->with('success', BillingFormat::money($applied).' of credit applied to open invoices.');
    }
}
