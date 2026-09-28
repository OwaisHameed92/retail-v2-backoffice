<?php

namespace App\Domain\Billing\Enums;

/**
 * How a company pays (module 1.12): the whole period in advance by hand (cash or bank transfer, module 1.8), or a
 * setup fee plus a recurring GoCardless Direct Debit, monthly or yearly.
 */
enum BillingMode: string
{
    case UpfrontCash = 'upfrontCash';
    case DirectDebit = 'directDebit';

    public function label(): string
    {
        return match ($this) {
            self::UpfrontCash => 'Upfront (cash or bank transfer)',
            self::DirectDebit => 'Direct Debit (GoCardless)',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $mode) => ['value' => $mode->value, 'label' => $mode->label()], self::cases());
    }
}
