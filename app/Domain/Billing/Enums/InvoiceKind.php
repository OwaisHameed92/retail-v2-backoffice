<?php

namespace App\Domain\Billing\Enums;

/**
 * What an invoice is for: tills for a billing period, the one-off setup fee (or one instalment of it), or the setup
 * fee of tills added later on a per-till setup fee plan (P11; the added tills stay on trial until it is paid). Only
 * subscription invoices count when checking whether a period is already invoiced.
 */
enum InvoiceKind: string
{
    case Subscription = 'subscription';
    case SetupFee = 'setupFee';
    case TillSetupFee = 'tillSetupFee';

    public function label(): string
    {
        return match ($this) {
            self::Subscription => 'Subscription',
            self::SetupFee => 'Setup fee',
            self::TillSetupFee => 'Setup fee (added tills)',
        };
    }

    /** A one-off setup fee, not a period: the business's own or one for added tills. */
    public function isSetupFee(): bool
    {
        return $this !== self::Subscription;
    }

    /**
     * Values of the one-off kinds (not periods), for `whereNotIn('kind', …)`.
     *
     * @return list<string>
     */
    public static function setupFeeValues(): array
    {
        return [self::SetupFee->value, self::TillSetupFee->value];
    }
}
