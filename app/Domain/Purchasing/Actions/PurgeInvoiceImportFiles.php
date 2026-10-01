<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Purchasing\Models\InvoiceImport;
use Carbon\CarbonImmutable;

/**
 * Deletes uploaded invoice files older than InvoiceImport::FILE_RETENTION_DAYS (module 6.5), for every company
 * (scheduled daily). The import's figures stay until the row itself is pruned.
 */
final class PurgeInvoiceImportFiles
{
    public function handle(): int
    {
        $count = 0;

        InvoiceImport::withoutCompanyScope()->whereNotNull('file_path')->whereNull('file_purged_at')
            ->where('created_at', '<', CarbonImmutable::now('UTC')->subDays(InvoiceImport::FILE_RETENTION_DAYS))
            ->chunkById(200, function ($imports) use (&$count) {
                foreach ($imports as $import) {
                    $import->deleteFile();
                    $import->saveQuietly();
                    $count++;
                }
            });

        return $count;
    }
}
