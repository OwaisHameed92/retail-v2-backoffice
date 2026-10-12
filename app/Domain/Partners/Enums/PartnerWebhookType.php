<?php

namespace App\Domain\Partners\Enums;

/**
 * Webhook events sent to a partner (docs/partner-api.md "Webhooks"). Each is sent at most once per tenant request
 * (one outbox row per request and type), with the same event id on every retry.
 */
enum PartnerWebhookType: string
{
    case Approved = 'tenant.approved';
    case Rejected = 'tenant.rejected';
    case SetupFeePaid = 'tenant.setup_fee_paid';
    case FirstTillActivated = 'tenant.first_till_activated';
    case Cancelled = 'tenant.cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::SetupFeePaid => 'Setup fee paid',
            self::FirstTillActivated => 'First till activated',
            self::Cancelled => 'Cancelled',
        };
    }
}
