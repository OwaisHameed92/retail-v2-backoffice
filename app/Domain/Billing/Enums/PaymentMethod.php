<?php

namespace App\Domain\Billing\Enums;

/**
 * How a payment reached us. Staff record cash, card (our card machine), bank transfers and anything else by hand; `online` is reserved
 * for the payment gateway (later), which records payments through the same RecordPayment action with its
 * gateway name and reference. `directDebit` is recorded by the GoCardless integration (module 1.12) only.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    /** Taken on our own card machine (owner rule 2026-10-05), recorded by hand like cash. */
    case Card = 'card';
    case BankTransfer = 'bankTransfer';
    case Other = 'other';
    case Online = 'online';
    case DirectDebit = 'directDebit';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::Card => 'Card',
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
     * How a setup fee (upfront) payment can be made: cash, card or bank transfer.
     *
     * @return list<self>
     */
    public static function setupFee(): array
    {
        return [self::Cash, self::Card, self::BankTransfer];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function setupFeeOptions(): array
    {
        return array_map(fn (self $method) => ['value' => $method->value, 'label' => $method->label()], self::setupFee());
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
