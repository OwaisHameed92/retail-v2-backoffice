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
 *
 * Demo businesses (demo:billing) have their own counters ("demo_invoice" → DEMO-INV-000001, sequence from
 * DEMO_OFFSET), so making or removing them never leaves a gap in the real numbers.
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

    /** Demo document sequences start here, far above any real one (the columns are unique). */
    public const DEMO_OFFSET = 9_000_000_000_000;

    /**
     * The next number of a sequence: [sequence, "INV-000042"]. Must run inside a transaction.
     *
     * @return array{0: int, 1: string}
     */
    public function next(string $sequence, bool $demo = false): array
    {
        if (! isset(self::PREFIXES[$sequence])) {
            throw new LogicException("Unknown document sequence \"{$sequence}\".");
        }

        if (DB::transactionLevel() === 0) {
            throw new LogicException('Document numbers must be taken inside the transaction that issues the document.');
        }

        $table = DB::table('billing_sequences');
        $name = $demo ? 'demo_'.$sequence : $sequence;

        if ($table->clone()->where('name', $name)->increment('last_value') === 0) {
            // The migration creates the rows; this only helps a database that lost one (and makes the demo ones).
            $table->clone()->insertOrIgnore(['name' => $name, 'last_value' => 0]);
            $table->clone()->where('name', $name)->increment('last_value');
        }

        $value = (int) $table->clone()->where('name', $name)->value('last_value');

        return $demo ? [self::DEMO_OFFSET + $value, 'DEMO-'.self::format($sequence, $value)] : [$value, self::format($sequence, $value)];
    }

    public static function format(string $sequence, int $value): string
    {
        return sprintf('%s-%06d', self::PREFIXES[$sequence], $value);
    }
}
