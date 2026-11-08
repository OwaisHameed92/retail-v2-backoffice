<?php

namespace App\Domain\Devices\Enums;

use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceState;
use Carbon\CarbonImmutable;

/**
 * Status of a device on the admin Devices page. camelCase values.
 *
 * Tills, from the licence's effective status ({@see LicenceState}) and its PC binding, first match wins:
 * revoked, suspended, expired, notActivated (key never entered), released (trading licence with no PC bound: freed
 * by Release, waiting to be activated again), expiring (grace, or trial / paid end within
 * `licence.api.expiring_days`, the same window the till is told "expiring"), active.
 *
 * Handhelds, from the app's sign-in token: signedIn (token live, user active) or signedOut (logged out, signed out
 * from the portal, or the user deactivated).
 *
 * The SQL twin of these rules is TillDevices::whereStatus() / HandheldDevices::whereStatus(); tests keep them in step.
 */
enum DeviceStatus: string
{
    case Active = 'active';
    case Expiring = 'expiring';
    case Released = 'released';
    case NotActivated = 'notActivated';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
    case SignedIn = 'signedIn';
    case SignedOut = 'signedOut';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Expiring => 'Expiring',
            self::Released => 'Released',
            self::NotActivated => 'Not activated',
            self::Expired => 'Expired',
            self::Suspended => 'Suspended',
            self::Revoked => 'Revoked',
            self::SignedIn => 'Signed in',
            self::SignedOut => 'Signed out',
        };
    }

    public function kind(): DeviceKind
    {
        return in_array($this, [self::SignedIn, self::SignedOut], true) ? DeviceKind::Handheld : DeviceKind::Till;
    }

    /** Past devices (a revoked licence, a signed-out handheld) are listed only when this status is asked for. */
    public function isPast(): bool
    {
        return in_array($this, [self::Revoked, self::SignedOut], true);
    }

    /** The status of a till from its licence's effective state and whether a PC is bound. */
    public static function forTill(LicenceState $state, bool $bound, CarbonImmutable $now): self
    {
        return match ($state->status) {
            LicenceStatus::Revoked => self::Revoked,
            LicenceStatus::Suspended => self::Suspended,
            LicenceStatus::Expired => self::Expired,
            LicenceStatus::Issued => self::NotActivated,
            default => match (true) {
                ! $bound => self::Released,
                $state->status === LicenceStatus::Grace,
                $state->endsAt !== null && $state->endsAt->lessThanOrEqualTo(self::expiringBefore($now)) => self::Expiring,
                default => self::Active,
            },
        };
    }

    /** A trading licence ending on or before this is "expiring". */
    public static function expiringBefore(CarbonImmutable $now): CarbonImmutable
    {
        return $now->addDays(max(0, (int) config('licence.api.expiring_days', 7)));
    }

    /**
     * @return list<array{value: string, label: string, kind: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status) => ['value' => $status->value, 'label' => $status->label(), 'kind' => $status->kind()->value], self::cases());
    }
}
