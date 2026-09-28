<?php

namespace App\Domain\Billing\GoCardless\Enums;

/**
 * A stored webhook event: pending (queued), processed, ignored (not ours, or a kind we do not act on) or failed
 * (kept for replay).
 */
enum EventStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
