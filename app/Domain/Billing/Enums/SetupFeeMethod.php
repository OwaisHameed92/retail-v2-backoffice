<?php

namespace App\Domain\Billing\Enums;

/**
 * How the setup fee is collected. Owner rule (2026-10-05): ALWAYS by hand (cash, card or bank transfer, recorded by an
 * admin), optionally in monthly instalments. `directDebit` is kept only so old rows still load; nothing reads it as
 * "collect by Direct Debit" any more (ChargeSetupFee never creates a GoCardless payment) and it is not offered.
 */
enum SetupFeeMethod: string
{
    case Manual = 'manual';
    case DirectDebit = 'directDebit';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'By hand (cash, card or bank transfer)',
            self::DirectDebit => 'Direct Debit (no longer used)',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return [['value' => self::Manual->value, 'label' => self::Manual->label()]];
    }
}
