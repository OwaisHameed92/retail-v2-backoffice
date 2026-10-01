<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Enums\InvoiceImportStatus;
use App\Domain\Purchasing\Invoices\InvoiceDraft;
use App\Domain\Purchasing\Invoices\InvoiceImportAccess;
use App\Domain\Purchasing\Jobs\ReadInvoiceJob;
use App\Domain\Purchasing\Models\InvoiceImport;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Starts an invoice import (module 6.5) for one open shop: stores the PDF or photo privately under the company's own
 * folder (`invoice-imports/{company}/`, random name, never public), then either queues the model to read it
 * (ReadInvoiceJob) or, when the model cannot be used (no key, switched off, allowance used) or the user chose to,
 * opens an empty draft to enter by hand. A file is optional for a manual entry.
 *
 *     $import = app(UploadInvoice::class)->handle($company, $user, $leeds, $request->file('file'), manual: false);
 */
final class UploadInvoice
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, User $user, Branch $shop, ?UploadedFile $file, bool $manual = false): InvoiceImport
    {
        if (! $shop->is_active) {
            throw ValidationException::withMessages(['shopId' => 'This shop is closed. Choose an open shop.']);
        }

        $read = ! $manual && InvoiceImportAccess::reader($user, $company)['available'];

        if (! $manual && ! $read) {
            $manual = true;
        }

        if ($read && $file === null) {
            throw ValidationException::withMessages(['file' => 'Choose the invoice to upload.']);
        }

        $import = new InvoiceImport([
            'branch_id' => $shop->id,
            'user_id' => $user->getKey(),
            'status' => $read ? InvoiceImportStatus::Reading : InvoiceImportStatus::Review,
            'method' => $read ? 'ai' : 'manual',
            'draft' => $read ? null : InvoiceDraft::blank(),
        ]);

        if ($file !== null) {
            $mime = (string) $file->getMimeType();
            $extension = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'][$mime] ?? 'bin';
            $import->fill([
                'file_name' => mb_substr(InvoiceDraft::text($file->getClientOriginalName(), 190) ?? 'invoice.'.$extension, 0, 190),
                'file_path' => $file->storeAs('invoice-imports/'.$company->id, Str::lower((string) Str::ulid()).'.'.$extension, InvoiceImport::DISK),
                'file_mime' => $mime,
                'file_size' => (int) $file->getSize(),
                'file_sha256' => hash_file('sha256', (string) $file->getRealPath()) ?: null,
            ]);
        }

        $import->save();

        $this->audit->handle('invoice_import.uploaded', $import, null, [
            'shop' => $shop->name, 'method' => $import->method, 'file' => $import->file_name,
        ]);

        if ($read) {
            ReadInvoiceJob::dispatch($import->id, $company->id, (int) $user->getKey());
        }

        return $import->refresh();
    }
}
