<?php

namespace App\Domain\Billing\Enums;

/**
 * What an invoice is for: tills for a billing period, or the one-off setup fee (or one instalment of it). Only
 * subscription invoices count when checking whether a period is already invoiced.
 */
enum InvoiceKind: string
{
    case Subscription = 'subscription';
    case SetupFee = 'setupFee';

    public function label(): string
    {
        return match ($this) {
            self::Subscription => 'Subscription',
            self::SetupFee => 'Setup fee',
        };
    }
}
