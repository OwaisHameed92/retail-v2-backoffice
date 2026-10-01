<?php

namespace App\Domain\Purchasing\Jobs;

use App\Domain\Purchasing\Actions\ReadInvoice;
use App\Domain\Purchasing\Enums\InvoiceImportStatus;
use App\Domain\Purchasing\Models\InvoiceImport;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Reads one uploaded invoice with the model (ReadInvoice) as its business and the user who uploaded it, off the web
 * request: a PDF can take the model a minute. The review screen polls until the import leaves `reading`. Not retried
 * (CallModel already retries the provider; a second paid call is not worth it): a failure marks the import failed.
 */
class ReadInvoiceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly string $importId,
        public readonly string $companyId,
        public readonly int $userId,
    ) {}

    public function handle(CurrentCompany $tenancy, ReadInvoice $read): void
    {
        $company = Company::query()->find($this->companyId);
        $user = User::query()->find($this->userId);

        if ($company === null) {
            return;
        }

        $tenancy->runAs($company, function () use ($read, $user, $company) {
            $import = InvoiceImport::query()->find($this->importId);

            if ($import === null) {
                return;
            }

            if ($user === null) {
                $import->fill(['status' => InvoiceImportStatus::Failed, 'error' => 'The user who uploaded this invoice no longer exists.'])->save();

                return;
            }

            $read->handle($import, $user, $company);
        });
    }

    public function failed(Throwable $e): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        app(CurrentCompany::class)->runAs($company, function () {
            InvoiceImport::query()->whereKey($this->importId)->where('status', InvoiceImportStatus::Reading->value)
                ->update(['status' => InvoiceImportStatus::Failed->value, 'error' => 'Something went wrong reading this document. Try again or enter it by hand.']);
        });
    }
}
