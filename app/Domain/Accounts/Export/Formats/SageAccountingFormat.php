<?php

namespace App\Domain\Accounts\Export\Formats;

use App\Domain\Accounts\Export\JournalFormat;
use App\Domain\Shared\Support\CsvText;

/**
 * Sage Accounting journal import: one row per line, rows with the same reference and date form one journal; the
 * ledger account code, debit or credit (the other blank) and the tax rate name. UK dates (dd/mm/yyyy).
 */
final class SageAccountingFormat implements JournalFormat
{
    public function header(): array
    {
        return ['Date', 'Reference', 'Journal Description', 'Ledger Account', 'Details', 'Debit', 'Credit', 'Tax Rate'];
    }

    public function rows(array $journal): array
    {
        $rows = [];

        foreach ($journal['lines'] as $line) {
            $rows[] = [
                Dates::uk($journal['date']), $journal['ref'], CsvText::safe($journal['narration']), CsvText::safe($line['theirCode']),
                CsvText::safe($line['name']), bccomp($line['debit'], '0', 2) > 0 ? $line['debit'] : '',
                bccomp($line['credit'], '0', 2) > 0 ? $line['credit'] : '', CsvText::safe($line['taxCode']),
            ];
        }

        return $rows;
    }
}
