<?php

namespace App\Domain\Billing\Enums;

/**
 * Status of an invoice. camelCase values (contract convention).
 *
 * draft → issued → partiallyPaid → paid; issued | partiallyPaid → overdue (past the due date, billing:run)
 * → paid. Any unpaid state → void. A draft has no number and can be edited or deleted; from issued on the
 * invoice itself never changes, only its status, payments, credits and stamps.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partiallyPaid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Issued => 'Issued',
            self::PartiallyPaid => 'Partly paid',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
            self::Void => 'Void',
        };
    }

    /** Issued and still owed: payments and credits can go on it. */
    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /** Has a number and counts as a sent document. */
    public function isIssued(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Issued, self::PartiallyPaid, self::Overdue];
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_map(fn (self $status) => $status->value, self::open());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status) => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
