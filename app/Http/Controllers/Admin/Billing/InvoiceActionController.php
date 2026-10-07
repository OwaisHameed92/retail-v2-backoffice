<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Domain\Billing\Actions\DeleteDraftInvoice;
use App\Domain\Billing\Actions\EmailInvoice;
use App\Domain\Billing\Actions\IssueCreditNote;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\UpdateDraftInvoice;
use App\Domain\Billing\Actions\VoidInvoice;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Support\BillingFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Billing\CreditNoteRequest;
use App\Http\Requests\Admin\Billing\UpdateDraftInvoiceRequest;
use App\Http\Requests\Admin\Billing\VoidInvoiceRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Changes to one invoice (module 1.8): `billing.manage` (route middleware).
 */
class InvoiceActionController extends Controller
{
    use FindsBillingRecords;

    public function update(UpdateDraftInvoiceRequest $request, string $invoice, UpdateDraftInvoice $update): RedirectResponse
    {
        $model = $update->handle($this->findInvoice($invoice), $request->validated('notes'), $request->lines());

        return back()->with('success', 'Draft saved. Total '.BillingFormat::money($model->total).'.');
    }

    public function destroy(string $invoice, DeleteDraftInvoice $delete): RedirectResponse
    {
        $model = $this->findInvoice($invoice);
        $delete->handle($model);

        return redirect()->route('admin.tenants.show', ['company' => $model->company_id, 'tab' => 'billing'])->with('success', 'Draft invoice deleted.');
    }

    public function issue(string $invoice, IssueInvoice $issue): RedirectResponse
    {
        $model = $issue->handle($this->findInvoice($invoice));

        $message = $model->status === InvoiceStatus::Paid
            ? "{$model->number} issued and paid from credit. The licences are renewed."
            : "{$model->number} issued".($model->sent_count > 0 ? ' and emailed with the PDF.' : '. Nobody to email yet: add a billing email, then send it.');

        return back()->with('success', $message);
    }

    /** "Email to customer" / "Send again": an admin's own send, never held (P11); sends held copies first. */
    public function send(string $invoice, EmailInvoice $send): RedirectResponse
    {
        $model = $this->findInvoice($invoice);
        $first = $model->sent_count === 0;
        $count = $send->handle($model);

        return back()->with('success', "{$model->number} ".($first ? 'emailed' : 'sent again').' to '.($count === 1 ? '1 address' : "{$count} addresses").'.');
    }

    public function void(VoidInvoiceRequest $request, string $invoice, VoidInvoice $void): RedirectResponse
    {
        [$voided, $draft] = $void->handle($this->findInvoice($invoice), (string) $request->validated('reason'), $request->boolean('redraft'));

        if ($draft !== null) {
            return redirect()->route('admin.billing.invoices.show', $draft->id)->with('success', "{$voided->number} is void. Here is a corrected draft to edit and issue.");
        }

        return back()->with('success', "{$voided->number} is void.");
    }

    public function credit(CreditNoteRequest $request, string $invoice, IssueCreditNote $credit): RedirectResponse
    {
        $note = $credit->handle($this->findInvoice($invoice), (string) $request->validated('amount'), (string) $request->validated('reason'));

        return back()->with('success', "Credit note {$note->number} for ".BillingFormat::money($note->total).' issued.');
    }
}
