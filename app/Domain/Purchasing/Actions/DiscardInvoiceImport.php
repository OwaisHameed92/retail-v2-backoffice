<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Enums\InvoiceImportStatus;
use App\Domain\Purchasing\Models\InvoiceImport;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Validation\ValidationException;

/**
 * Discards an invoice import that was not confirmed (module 6.5): the uploaded file is deleted at once, the row stays
 * as `discarded` (audited) until the retention period ends. Nothing else was ever created by it.
 */
final class DiscardInvoiceImport
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(InvoiceImport $import): InvoiceImport
    {
        if (! $import->status->isOpen()) {
            throw ValidationException::withMessages(['import' => 'This import is '.mb_strtolower($import->status->label()).' and cannot be discarded.']);
        }

        $before = $import->status->value;
        $import->deleteFile();
        $import->status = InvoiceImportStatus::Discarded;
        $import->save();

        $this->audit->handle('invoice_import.discarded', $import, ['status' => $before], ['status' => 'discarded'], ['invoice_number' => $import->invoice_number]);

        return $import;
    }
}
