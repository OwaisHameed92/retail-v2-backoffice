<?php

namespace App\Domain\StockDrafts\Enums;

/**
 * Where a stock draft is (contract proposal pda-stock-drafts §6): `pending` while the portal owns it (it may still
 * be changed or cancelled), `withTill` once it was sent in the shop's pull (the till's now, read only here),
 * `confirmed` / `rejected` once the till pushed its outcome, `cancelled` when withdrawn before it was ever sent.
 */
enum StockDraftState: string
{
    case Pending = 'pending';
    case WithTill = 'withTill';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** States the pull still carries (the till may not have it yet, or may pull it again from an older cursor). */
    public const OPEN = ['pending', 'withTill'];

    /** The handheld's `portal_state`: pending / confirmed / rejected / cancelled. */
    public function portalState(): string
    {
        return match ($this) {
            self::Pending, self::WithTill => 'pending',
            self::Confirmed => 'confirmed',
            self::Rejected => 'rejected',
            self::Cancelled => 'cancelled',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this->value, self::OPEN, true);
    }
}
