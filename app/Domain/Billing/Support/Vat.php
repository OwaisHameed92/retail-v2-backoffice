<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Shared\Support\Money;

/**
 * Our VAT: on when we are VAT registered (`billing.vat.enabled`) and the company is billed with VAT.
 */
final class Vat
{
    public static function enabled(): bool
    {
        return (bool) config('billing.vat.enabled', true);
    }

    /** Percent as a decimal string ("20.00"), or "0.00" when no VAT applies. */
    public static function rateFor(BillingAccount $account): string
    {
        return self::enabled() && $account->vat_applies ? self::rate() : '0.00';
    }

    public static function rate(): string
    {
        return Money::normalise((string) config('billing.vat.rate', '20.00'));
    }

    public static function number(): ?string
    {
        $number = trim((string) config('billing.vat.number', ''));

        return self::enabled() && $number !== '' ? $number : null;
    }
}
