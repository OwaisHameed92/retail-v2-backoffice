<?php

namespace App\Domain\Billing\Enums;

/**
 * How the setup fee is collected: recorded by hand (cash or bank transfer, through Record payment) or taken by
 * GoCardless on the mandate. Either way it can be split into monthly instalments.
 */
enum SetupFeeMethod: string
{
    case Manual = 'manual';
    case DirectDebit = 'directDebit';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Cash or bank transfer',
            self::DirectDebit => 'Direct Debit',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $method) => ['value' => $method->value, 'label' => $method->label()], self::cases());
    }
}
