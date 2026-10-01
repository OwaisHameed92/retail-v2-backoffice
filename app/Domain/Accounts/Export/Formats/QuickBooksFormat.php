<?php

namespace App\Domain\Accounts\Export\Formats;

use App\Domain\Accounts\Export\JournalFormat;
use App\Domain\Shared\Support\CsvText;

/**
 * QuickBooks Online journal entry import: one row per line, rows with the same JournalNo form one entry; debits and
 * credits in their own columns (blank when nothing). `AccountName` takes the account's name or number as set up in
 * QuickBooks. UK dates (dd/mm/yyyy).
 */
final class QuickBooksFormat implements JournalFormat
{
    public function header(): array
    {
        return ['JournalNo', 'JournalDate', 'AccountName', 'Debits', 'Credits', 'Description', 'Name', 'TaxCode', 'Location', 'Class', 'Memo'];
    }

    public function rows(array $journal): array
    {
        $rows = [];

        foreach ($journal['lines'] as $line) {
            $rows[] = [
                $journal['ref'], Dates::uk($journal['date']), CsvText::safe($line['theirCode']),
                bccomp($line['debit'], '0', 2) > 0 ? $line['debit'] : '', bccomp($line['credit'], '0', 2) > 0 ? $line['credit'] : '',
                CsvText::safe($line['name']), '', CsvText::safe($line['taxCode']), '', '', CsvText::safe($journal['narration']),
            ];
        }

        return $rows;
    }
}
