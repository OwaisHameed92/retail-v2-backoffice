<?php

namespace App\Domain\Accounts\Export\Formats;

use App\Domain\Accounts\Export\JournalFormat;
use App\Domain\Shared\Support\CsvText;

/**
 * Sage 50 Accounts audit trail transaction import: `JD` (journal debit) / `JC` (journal credit) rows, positive net
 * amounts, a tax code with a tax amount of 0.00 (the VAT is its own line), department 0. Reference at most 30
 * characters and details at most 60, as Sage 50 allows. UK dates (dd/mm/yyyy).
 */
final class Sage50Format implements JournalFormat
{
    public function header(): array
    {
        return ['Type', 'Account Reference', 'Nominal A/C Ref', 'Department Code', 'Date', 'Reference', 'Details', 'Net Amount', 'Tax Code', 'Tax Amount', 'Exchange Rate', 'Extra Reference', 'User Name', 'Project Refn', 'Cost Code Refn'];
    }

    public function rows(array $journal): array
    {
        $rows = [];

        foreach ($journal['lines'] as $line) {
            $isDebit = bccomp($line['debit'], '0', 2) > 0;
            $rows[] = [
                $isDebit ? 'JD' : 'JC', '', CsvText::safe($line['theirCode']), '0', Dates::uk($journal['date']),
                mb_substr($journal['ref'], 0, 30), CsvText::safe(mb_substr($line['name'].' - '.$journal['shop'], 0, 60)),
                $isDebit ? $line['debit'] : $line['credit'], CsvText::safe($line['taxCode']), '0.00', '', '', 'SSPOS', '', '',
            ];
        }

        return $rows;
    }
}
