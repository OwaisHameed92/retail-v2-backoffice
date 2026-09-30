<?php

namespace App\Domain\Billing\Enums;

/**
 * What a business asks Switch & Save for from My subscription (module 4.10). Neither is done by the business itself:
 * licences are never cancelled from the portal, and a new bank account needs our Direct Debit subscription moved.
 */
enum BillingRequestKind: string
{
    case Cancel = 'cancel';
    case ChangeBank = 'changeBank';

    public function label(): string
    {
        return match ($this) {
            self::Cancel => 'Cancellation',
            self::ChangeBank => 'Bank account change',
        };
    }

    /** "cancel the subscription" / "change the bank account for the Direct Debit" */
    public function what(): string
    {
        return match ($this) {
            self::Cancel => 'to cancel the subscription',
            self::ChangeBank => 'to change the bank account for the Direct Debit',
        };
    }
}
