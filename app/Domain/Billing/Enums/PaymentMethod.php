<?php

namespace App\Domain\Billing\Enums;

/**
 * How a payment reached us. Staff record cash, bank transfers and anything else by hand; `online` is reserved
 * for the payment gateway (later), which records payments through the same RecordPayment action with its
 * gateway name and reference. `directDebit` is recorded by the GoCardless integration (module 1.12) only.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bankTransfer';
    case Other = 'other';
    case Online = 'online';
    case DirectDebit = 'directDebit';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank transfer',
            self::Other => 'Other',
            self::Online => 'Online',
            self::DirectDebit => 'Direct Debit',
        };
    }

    /** Staff can record this method by hand. */
    public function isManual(): bool
    {
        return ! in_array($this, [self::Online, self::DirectDebit], true);
    }

    /**
     * @return list<self>
     */
    public static function manual(): array
    {
        return array_values(array_filter(self::cases(), fn (self $method) => $method->isManual()));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(bool $manualOnly = false): array
    {
        $cases = $manualOnly ? self::manual() : self::cases();

        return array_map(fn (self $method) => ['value' => $method->value, 'label' => $method->label()], $cases);
    }
}
