<?php

namespace App\Domain\Billing\Support;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gap-free document numbers: INV-000001 (invoices), CN-000001 (credit notes), PAY-000001 (payment receipts).
 *
 * The counter row is incremented with an UPDATE inside the caller's transaction. The UPDATE locks the row until
 * the transaction ends (MySQL row lock; SQLite takes the database write lock), so concurrent callers queue up
 * and never get the same number, and a rolled-back transaction gives its number back: no gaps. The number is
 * only taken when a document is issued (drafts have none), in the same transaction that issues it.
 */
final class DocumentNumbers
{
    public const INVOICE = 'invoice';

    public const CREDIT_NOTE = 'credit_note';

    public const PAYMENT = 'payment';

    private const PREFIXES = [
        self::INVOICE => 'INV',
        self::CREDIT_NOTE => 'CN',
        self::PAYMENT => 'PAY',
    ];

    /**
     * The next number of a sequence: [sequence, "INV-000042"]. Must run inside a transaction.
     *
     * @return array{0: int, 1: string}
     */
    public function next(string $sequence): array
    {
        if (! isset(self::PREFIXES[$sequence])) {
            throw new LogicException("Unknown document sequence \"{$sequence}\".");
        }

        if (DB::transactionLevel() === 0) {
            throw new LogicException('Document numbers must be taken inside the transaction that issues the document.');
        }

        $table = DB::table('billing_sequences');

        if ($table->clone()->where('name', $sequence)->increment('last_value') === 0) {
            // The migration creates the rows; this only helps a database that lost one.
            $table->clone()->insertOrIgnore(['name' => $sequence, 'last_value' => 0]);
            $table->clone()->where('name', $sequence)->increment('last_value');
        }

        $value = (int) $table->clone()->where('name', $sequence)->value('last_value');

        return [$value, self::format($sequence, $value)];
    }

    public static function format(string $sequence, int $value): string
    {
        return sprintf('%s-%06d', self::PREFIXES[$sequence], $value);
    }
}
