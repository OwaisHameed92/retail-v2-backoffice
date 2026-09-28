<?php

namespace App\Domain\Billing\GoCardless\Enums;

/**
 * A GoCardless payment's status. confirmed / paidOut = the money is ours (recorded as a payment); failed /
 * chargedBack = dunning; cancelled / customerApprovalDenied = never collected.
 */
enum PaymentStatus: string
{
    case PendingCustomerApproval = 'pendingCustomerApproval';
    case PendingSubmission = 'pendingSubmission';
    case Submitted = 'submitted';
    case Confirmed = 'confirmed';
    case PaidOut = 'paidOut';
    case Cancelled = 'cancelled';
    case CustomerApprovalDenied = 'customerApprovalDenied';
    case Failed = 'failed';
    case ChargedBack = 'chargedBack';

    public static function fromGoCardless(string $status): self
    {
        return self::tryFrom(lcfirst(str_replace('_', '', ucwords($status, '_')))) ?? self::Failed;
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingCustomerApproval => 'Awaiting approval',
            self::PendingSubmission => 'Scheduled',
            self::Submitted => 'Submitted',
            self::Confirmed => 'Collected',
            self::PaidOut => 'Paid out',
            self::Cancelled => 'Cancelled',
            self::CustomerApprovalDenied => 'Denied',
            self::Failed => 'Failed',
            self::ChargedBack => 'Charged back',
        };
    }

    public function isCollected(): bool
    {
        return in_array($this, [self::Confirmed, self::PaidOut], true);
    }

    public function isPending(): bool
    {
        return in_array($this, [self::PendingCustomerApproval, self::PendingSubmission, self::Submitted], true);
    }

    public function isProblem(): bool
    {
        return in_array($this, [self::Failed, self::ChargedBack], true);
    }

    /** Never going to be collected: no invoice is created for a payment first seen like this. */
    public function isDead(): bool
    {
        return in_array($this, [self::Cancelled, self::CustomerApprovalDenied], true);
    }

    /**
     * @return list<string>
     */
    public static function pendingValues(): array
    {
        return [self::PendingCustomerApproval->value, self::PendingSubmission->value, self::Submitted->value];
    }
}
