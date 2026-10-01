<?php

namespace App\Domain\Demo\Support;

use App\Domain\Reporting\Demo\DemoShop;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Posts double-entry journals as the till does (`JournalEntry` + `JournalLine`, on the shop's main till), using the
 * shop's own copy of the chart (SetupBuilder::ACCOUNTS). Debits always equal credits.
 */
final readonly class DemoJournal
{
    public function __construct(private DemoPush $push) {}

    /**
     * @param  list<array{0: string, 1: int, 2: int, 3?: string}>  $lines  account code, debit pence, credit pence, memo
     */
    public function post(DemoShop $shop, string $key, CarbonImmutable $at, string $refType, string $refId, string $memo, array $lines, string $userId): void
    {
        $lines = array_values(array_filter($lines, fn (array $l) => $l[1] !== 0 || $l[2] !== 0));
        $debits = array_sum(array_column($lines, 1));
        $credits = array_sum(array_column($lines, 2));

        if ($debits !== $credits) {
            throw new InvalidArgumentException("Demo journal {$key} does not balance: {$debits} / {$credits}.");
        }

        if ($lines === []) {
            return;
        }

        $register = $shop->registers[0]['id'];
        $entryId = $shop->id("journal|{$key}");
        $this->push->add($shop, 'JournalEntry', $entryId, [
            'date' => $at->setTimezone('Europe/London')->toDateString(), 'refType' => $refType, 'refId' => $refId, 'memo' => $memo,
            'periodId' => '', 'postedAt' => DemoBusiness::iso($at), 'postedByUserId' => $userId, 'registerId' => $register,
            'reversesEntryId' => null, 'reversedByEntryId' => null, 'isReversed' => false,
            'totalDebits' => $debits / 100, 'totalCredits' => $credits / 100, 'branchId' => $shop->branchId,
        ], $at);

        foreach ($lines as $i => $line) {
            $this->push->add($shop, 'JournalLine', $shop->id("journal|{$key}|{$i}"), [
                'journalEntryId' => $entryId, 'accountId' => $shop->id("account|{$line[0]}"), 'accountCode' => $line[0],
                'debit' => $line[1] / 100, 'credit' => $line[2] / 100, 'vatRateId' => null, 'branchId' => $shop->branchId,
                'registerId' => $register, 'memo' => $line[3] ?? $memo, 'balance' => 0,
            ], $at);
        }
    }
}
