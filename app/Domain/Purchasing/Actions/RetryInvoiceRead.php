<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Enums\InvoiceImportStatus;
use App\Domain\Purchasing\Invoices\InvoiceImportAccess;
use App\Domain\Purchasing\Jobs\ReadInvoiceJob;
use App\Domain\Purchasing\Models\InvoiceImport;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Asks the model to read an invoice again after it failed (module 6.5), as the user asking (metered to them). Needs
 * the file still here and the model available; otherwise the user enters the invoice by hand.
 */
final class RetryInvoiceRead
{
    /**
     * @throws ValidationException
     */
    public function handle(InvoiceImport $import, User $user, Company $company): InvoiceImport
    {
        if ($import->status !== InvoiceImportStatus::Failed || ! $import->hasFile()) {
            throw ValidationException::withMessages(['import' => 'Only a failed import with its file can be read again.']);
        }

        $reader = InvoiceImportAccess::reader($user, $company);

        if (! $reader['available']) {
            throw ValidationException::withMessages(['import' => $reader['message'] ?? 'Reading invoices is not available now.']);
        }

        $import->fill(['status' => InvoiceImportStatus::Reading, 'method' => 'ai', 'error' => null])->save();
        ReadInvoiceJob::dispatch($import->id, $company->id, (int) $user->getKey());

        return $import->refresh();
    }
}
