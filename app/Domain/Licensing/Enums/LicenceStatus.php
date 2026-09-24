<?php

namespace App\Domain\Licensing\Enums;

use App\Domain\Licensing\LicenceState;

/**
 * Status of a licence (one per till). camelCase values, as sent to the till in the licence token.
 *
 * Lifecycle: issued (never activated) → trial | active → grace → expired. Admin: suspended, revoked (final).
 * The stored status is the licence's own state (kept current by `licences:refresh`); what the till is told
 * also depends on the company, branch and till: see {@see LicenceState}.
 */
enum LicenceStatus: string
{
    case Issued = 'issued';
    case Trial = 'trial';
    case Active = 'active';
    case Grace = 'grace';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Issued => 'Issued',
            self::Trial => 'Trial',
            self::Active => 'Active',
            self::Grace => 'Grace',
            self::Expired => 'Expired',
            self::Suspended => 'Suspended',
            self::Revoked => 'Revoked',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Issued => 'Key issued, not yet entered on a till.',
            self::Trial => 'Free trial, started on first activation.',
            self::Active => 'Paid and trading.',
            self::Grace => 'Past its end date. The till still trades for the grace days.',
            self::Expired => 'The till is locked until the licence is renewed.',
            self::Suspended => 'Locked by us. The till stops trading at its next check-in.',
            self::Revoked => 'Permanently cancelled. The key never works again.',
        };
    }

    /** The till may take sales. */
    public function canTrade(): bool
    {
        return in_array($this, [self::Trial, self::Active, self::Grace], true);
    }

    /** No way back: a revoked licence stays revoked. */
    public function isFinal(): bool
    {
        return $this === self::Revoked;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status) => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
