<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceState;
use Carbon\CarbonImmutable;

/**
 * Our effective licence status ({@see LicenceState}) as the contract's `status` (v1.4.1 §17.5, §17.15.2):
 *
 * | LicenceState | status |
 * |---|---|
 * | revoked | revoked |
 * | suspended (licence, company, branch or till) | suspended |
 * | expired, grace (past the end date: the token's expiresAt has passed), never-activated issued | expired |
 * | trial, active | expiring when the token's expiresAt is within config('licence.api.expiring_days'), else active |
 *
 * `released` is not a licence state: it answers an install that was released from the key. `seatLimit` is the
 * branch model only and never sent.
 */
final class TillStatus
{
    public const ACTIVE = 'active';

    public const EXPIRING = 'expiring';

    public const EXPIRED = 'expired';

    public const SUSPENDED = 'suspended';

    public const REVOKED = 'revoked';

    public const RELEASED = 'released';

    public static function of(LicenceState $state, CarbonImmutable $expiresAt, CarbonImmutable $now): string
    {
        return match ($state->status) {
            LicenceStatus::Revoked => self::REVOKED,
            LicenceStatus::Suspended => self::SUSPENDED,
            LicenceStatus::Trial, LicenceStatus::Active => $now->addDays(max(0, (int) config('licence.api.expiring_days', 7)))->greaterThanOrEqualTo($expiresAt)
                ? self::EXPIRING
                : self::ACTIVE,
            default => self::EXPIRED,
        };
    }

    /** The till trades on this status (§17.9). */
    public static function trades(string $status): bool
    {
        return $status === self::ACTIVE || $status === self::EXPIRING;
    }

    /** nextCheckAfterSeconds for this status, within the schema's 60 s – 30 days. */
    public static function nextCheckAfterSeconds(string $status): int
    {
        $seconds = (int) config(self::trades($status) ? 'licence.api.next_check_seconds' : 'licence.api.next_check_locked_seconds', 86400);

        return max(60, min(2592000, $seconds));
    }
}
