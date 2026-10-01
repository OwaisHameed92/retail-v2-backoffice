<?php

namespace App\Domain\Accounts\Export;

/**
 * One package's journal CSV (gap #8): its header row and the rows of one exported journal.
 *
 * @phpstan-import-type Journal from JournalSummary
 */
interface JournalFormat
{
    /** @return list<string> */
    public function header(): array;

    /**
     * @param  Journal  $journal
     * @return list<list<string>>
     */
    public function rows(array $journal): array;
}
