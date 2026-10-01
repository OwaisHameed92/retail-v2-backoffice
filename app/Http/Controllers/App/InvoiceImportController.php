<?php

namespace App\Http\Controllers\App;

use App\Domain\Purchasing\Actions\ConfirmInvoiceImport;
use App\Domain\Purchasing\Actions\DiscardInvoiceImport;
use App\Domain\Purchasing\Actions\RetryInvoiceRead;
use App\Domain\Purchasing\Actions\SaveInvoiceReview;
use App\Domain\Purchasing\Actions\UploadInvoice;
use App\Domain\Purchasing\Enums\InvoiceImportStatus;
use App\Domain\Purchasing\Invoices\InvoiceImportAccess;
use App\Domain\Purchasing\Models\InvoiceImport;
use App\Domain\Purchasing\Queries\InvoiceImportList;
use App\Domain\Purchasing\Queries\InvoiceReviewPage;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Purchasing\ConfirmInvoiceImportRequest;
use App\Http\Requests\App\Purchasing\InvoiceImportRequest;
use App\Http\Requests\App\Purchasing\InvoiceReviewRequest;
use App\Http\Requests\App\Purchasing\UploadInvoiceRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Invoice import (module 6.5, `company.can:purchasing.manage`, plan feature `assist_invoice_scan` for changes):
 * upload a supplier invoice or delivery note, the model reads it (or the user enters it by hand), review and correct
 * it, then confirm (a head-office order and cost price updates when asked). One-shop users work on their own shop.
 */
class InvoiceImportController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('app/purchasing/invoice-import', InvoiceImportList::for($request));
    }

    public function store(UploadInvoiceRequest $request, UploadInvoice $upload, CurrentCompany $current): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $shop = Branch::query()->findOrFail($request->validated('shopId'));
        $import = $upload->handle($current->require(), $user, $shop, $request->file('file'), $request->boolean('manual'));

        $message = match ($import->status) {
            InvoiceImportStatus::Reading => 'Reading the invoice. This usually takes under a minute.',
            InvoiceImportStatus::Review => $import->method === 'ai' ? 'The invoice is read. Check it before you confirm.' : 'Enter the invoice below.',
            default => $import->error ?? 'We could not read this document.',
        };

        return redirect()->route('app.purchasing.invoices.import.show', $import->id)
            ->with($import->status === InvoiceImportStatus::Failed ? 'error' : 'success', $message);
    }

    public function show(Request $request, string $import): Response
    {
        return Inertia::render('app/purchasing/invoice-review', InvoiceReviewPage::for($request, $this->find($import)));
    }

    public function file(string $import): StreamedResponse
    {
        $model = $this->find($import);
        abort_unless($model->hasFile() && Storage::disk(InvoiceImport::DISK)->exists((string) $model->file_path), 404);

        return Storage::disk(InvoiceImport::DISK)->response((string) $model->file_path, $model->file_name, [
            'Content-Type' => (string) $model->file_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], 'inline');
    }

    public function update(InvoiceReviewRequest $request, string $import, SaveInvoiceReview $save): RedirectResponse
    {
        $save->handle($this->find($import), $request->validated());

        return back()->with('success', 'Saved and checked again.');
    }

    public function confirm(ConfirmInvoiceImportRequest $request, string $import, ConfirmInvoiceImport $confirm): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $confirm->handle($this->find($import), $user, [
            'order' => $request->validated('order'),
            'costUpdates' => $request->validated('costUpdates') ?? [],
            'acknowledged' => $request->boolean('acknowledged'),
        ]);

        $parts = array_filter([
            'Invoice '.($model->invoice_number ?? '').' is confirmed.',
            isset($model->result['order']['reference']) ? "Order {$model->result['order']['reference']} is ".($model->result['order']['status'] === 'sent' ? 'sent.' : 'saved as a draft.') : null,
            ($n = count($model->result['costUpdates'] ?? [])) > 0 ? "{$n} cost ".($n === 1 ? 'price' : 'prices').' updated.' : null,
        ]);

        return back()->with('success', implode(' ', $parts));
    }

    public function retry(InvoiceImportRequest $request, string $import, RetryInvoiceRead $retry, CurrentCompany $current): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $retry->handle($this->find($import), $user, $current->require());

        return back()->with($model->status === InvoiceImportStatus::Failed ? 'error' : 'success', $model->status === InvoiceImportStatus::Failed
            ? ($model->error ?? 'We could not read this document.')
            : 'Reading the invoice again.');
    }

    public function discard(InvoiceImportRequest $request, string $import, DiscardInvoiceImport $discard): RedirectResponse
    {
        $discard->handle($this->find($import));

        return redirect()->route('app.purchasing.invoices.import.index')->with('success', 'The import is discarded and its file deleted.');
    }

    private function find(string $id): InvoiceImport
    {
        return InvoiceImportAccess::visible()->findOrFail($id);
    }
}
