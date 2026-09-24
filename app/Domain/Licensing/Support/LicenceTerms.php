<?php

namespace App\Domain\Licensing\Support;

use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Models\Plan;
use Carbon\CarbonImmutable;

/**
 * Date rules of a licence, on its own (no company/branch/till context; that is LicenceState's job).
 *
 * - Trial: `trial_ends_at` = first activation + plan trial_days (set by module 1.5), then `grace_days` =
 *   plan trial_grace_days.
 * - Paid: `expires_at` (set by RenewLicence) + `grace_days` = plan grace_days. Once paid, `expires_at` wins
 *   over the trial end.
 * - Days are exact 24-hour periods in UTC, like the token's validUntil.
 */
final class LicenceTerms
{
    /** End of the current period: the paid expiry, else the trial end. */
    public static function endsAt(Licence $licence): ?CarbonImmutable
    {
        return $licence->expires_at ?? $licence->trial_ends_at;
    }

    /** When the grace after endsAt() runs out and the till locks. */
    public static function graceEndsAt(Licence $licence): ?CarbonImmutable
    {
        return self::endsAt($licence)?->addDays(max(0, $licence->grace_days));
    }

    public static function isPaid(Licence $licence): bool
    {
        return $licence->expires_at !== null;
    }

    /**
     * The status the dates give, ignoring suspension and revocation: issued (never activated), trial, active,
     * grace or expired. An activated licence with no end date at all fails closed (expired).
     */
    public static function naturalStatus(Licence $licence, CarbonImmutable $now): LicenceStatus
    {
        if ($licence->activated_at === null) {
            return LicenceStatus::Issued;
        }

        $endsAt = self::endsAt($licence);
        $graceEndsAt = self::graceEndsAt($licence);

        return match (true) {
            $endsAt === null || $graceEndsAt === null => LicenceStatus::Expired,
            $now->lessThan($endsAt) => self::isPaid($licence) ? LicenceStatus::Active : LicenceStatus::Trial,
            $now->lessThan($graceEndsAt) => LicenceStatus::Grace,
            default => LicenceStatus::Expired,
        };
    }

    /** Grace days that apply to this licence on a plan: paid grace once renewed, trial grace before. */
    public static function graceDaysFor(Licence $licence, Plan $plan): int
    {
        return self::isPaid($licence) ? $plan->grace_days : $plan->trial_grace_days;
    }

    /**
     * The status to store after a change of dates: suspended, revoked and issued licences keep theirs.
     */
    public static function storedStatusAfterDateChange(Licence $licence, CarbonImmutable $now): LicenceStatus
    {
        return in_array($licence->status, [LicenceStatus::Suspended, LicenceStatus::Revoked, LicenceStatus::Issued], true)
            ? $licence->status
            : self::naturalStatus($licence, $now);
    }
}
