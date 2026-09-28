<?php

namespace App\Domain\Licensing\Support;

use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Signing\Sspos\TokenKind;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;

/**
 * Applies a branch's licence settings (module 1.11) to one of its till licences. The caller saves.
 *
 * Dates: with a length, the term runs from the branch's start date (else the till's first activation) for that
 * length: `full` sets the paid expiry (`expires_at`, paid grace), `trial` the trial end (`trial_ends_at`, trial
 * grace, no paid expiry). Without a length nothing changes: the plan's trial starts on activation and billing
 * renewals set the paid expiry, as before. A licence not yet activated and without a start date gets its dates
 * on activation (ActivateLicence).
 */
final class BranchLicenceTerm
{
    /** The start of a key's term: the branch's start date, else the till's first activation. */
    public static function start(Licence $licence, ?Branch $branch): ?CarbonImmutable
    {
        return $branch->licence_valid_from ?? $licence->activated_at;
    }

    /** Sets the licence's dates from the branch's kind and length. True when a length applied. */
    public static function applyDates(Licence $licence, Branch $branch, CarbonImmutable $now): bool
    {
        $start = self::start($licence, $branch);

        if ($branch->licence_length === null || $branch->licence_length_unit === null || $start === null) {
            return false;
        }

        $end = $branch->licence_length_unit->add($start, $branch->licence_length);
        $plan = $licence->plan;

        if ($branch->licence_kind === TokenKind::Full) {
            $licence->expires_at = $end;
            $licence->grace_days = $plan !== null ? $plan->grace_days : $licence->grace_days;
        } else {
            $licence->expires_at = null;
            $licence->trial_ends_at = $end;
            $licence->grace_days = $plan !== null ? $plan->trial_grace_days : $licence->grace_days;
        }

        $licence->status = LicenceTerms::storedStatusAfterDateChange($licence, $now);

        return true;
    }

    /**
     * The branch's features for its keys, else the plan's.
     *
     * @param  iterable<Feature>  $planFeatures
     * @return list<Feature>
     */
    public static function features(Branch $branch, iterable $planFeatures): array
    {
        return Feature::normalise($branch->licence_features ?? $planFeatures);
    }
}
