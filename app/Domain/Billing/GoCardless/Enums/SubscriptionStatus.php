<?php

namespace App\Domain\Billing\GoCardless\Enums;

/**
 * A GoCardless subscription's status. Only an active one creates payments.
 */
enum SubscriptionStatus: string
{
    case PendingCustomerApproval = 'pendingCustomerApproval';
    case CustomerApprovalDenied = 'customerApprovalDenied';
    case Active = 'active';
    case Paused = 'paused';
    case Finished = 'finished';
    case Cancelled = 'cancelled';

    public static function fromGoCardless(string $status): self
    {
        return self::tryFrom(lcfirst(str_replace('_', '', ucwords($status, '_')))) ?? self::Cancelled;
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingCustomerApproval => 'Awaiting approval',
            self::CustomerApprovalDenied => 'Denied',
            self::Active => 'Active',
            self::Paused => 'Paused',
            self::Finished => 'Finished',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Still ours to collect with (active or paused), so billing:run does not invoice the company by hand. */
    public function isLive(): bool
    {
        return in_array($this, [self::PendingCustomerApproval, self::Active, self::Paused], true);
    }
}
