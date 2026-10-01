<?php

namespace App\Domain\Accounts\Export\Formats;

use App\Domain\Accounts\Export\JournalFormat;
use App\Domain\Shared\Support\CsvText;

/**
 * Xero's manual journal import template: one row per line; `*Amount` is positive for a debit and negative for a
 * credit; rows with the same narration and date form one journal. UK dates (dd/mm/yyyy). Tracking left blank (a
 * tracking option that does not exist in Xero would fail the import).
 */
final class XeroFormat implements JournalFormat
{
    public function header(): array
    {
        return ['*Narration', '*Date', 'Description', '*AccountCode', '*TaxRate', '*Amount', 'TrackingName1', 'TrackingOption1', 'TrackingName2', 'TrackingOption2'];
    }

    public function rows(array $journal): array
    {
        $rows = [];

        foreach ($journal['lines'] as $line) {
            $amount = bccomp($line['debit'], '0', 2) > 0 ? $line['debit'] : bcsub('0', $line['credit'], 2);
            $rows[] = [
                CsvText::safe($journal['narration'].' ('.$journal['ref'].')'), Dates::uk($journal['date']), CsvText::safe($line['name']),
                CsvText::safe($line['theirCode']), CsvText::safe($line['taxCode']), $amount, '', '', '', '',
            ];
        }

        return $rows;
    }
}
