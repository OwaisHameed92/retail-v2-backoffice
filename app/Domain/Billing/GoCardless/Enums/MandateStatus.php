<?php

namespace App\Domain\Billing\GoCardless\Enums;

/**
 * A GoCardless mandate's status (their snake_case values mapped to our camelCase). Payments can be created once
 * the mandate is pending submission, submitted or active ("usable").
 */
enum MandateStatus: string
{
    case PendingCustomerApproval = 'pendingCustomerApproval';
    case PendingSubmission = 'pendingSubmission';
    case Submitted = 'submitted';
    case Active = 'active';
    case SuspendedByPayer = 'suspendedByPayer';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Consumed = 'consumed';
    case Blocked = 'blocked';

    public static function fromGoCardless(string $status): self
    {
        return self::tryFrom(lcfirst(str_replace('_', '', ucwords($status, '_')))) ?? self::Failed;
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingCustomerApproval => 'Waiting for the customer',
            self::PendingSubmission, self::Submitted => 'Being set up',
            self::Active => 'Active',
            self::SuspendedByPayer => 'Suspended by the customer',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
            self::Consumed => 'Used up',
            self::Blocked => 'Blocked',
        };
    }

    /** GoCardless accepts payments on it. */
    public function isUsable(): bool
    {
        return in_array($this, [self::PendingSubmission, self::Submitted, self::Active], true);
    }

    /** The customer's authorisation is gone: a new mandate is needed. */
    public function isLost(): bool
    {
        return in_array($this, [self::Failed, self::Cancelled, self::Expired, self::Consumed, self::Blocked, self::SuspendedByPayer], true);
    }
}
