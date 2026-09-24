<?php

namespace App\Domain\Ai\Enums;

/**
 * Lifecycle of a change proposed by a write tool: pending → confirmed (executed once) | cancelled | expired | failed.
 */
enum PendingActionStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for confirmation',
            self::Confirmed => 'Confirmed',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
            self::Failed => 'Failed',
        };
    }
}
