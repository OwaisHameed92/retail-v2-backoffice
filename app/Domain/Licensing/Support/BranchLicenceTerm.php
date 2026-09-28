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

        $end = CarbonImmutable::instance($branch->licence_length_unit->add($start, $branch->licence_length));
        $plan = $licence->plan;
        $paidUntil = $licence->expires_at !== null ? CarbonImmutable::instance($licence->expires_at) : null;

        // Owner rule (2026-09-29): a new term never shortens a date the customer already has, and never turns a
        // paid licence back into a trial. Shortening is a separate, explicit action.
        if ($branch->licence_kind === TokenKind::Full) {
            $licence->expires_at = $paidUntil !== null && $paidUntil->greaterThan($end) ? $paidUntil : $end;
            $licence->grace_days = $plan !== null ? $plan->grace_days : $licence->grace_days;
        } elseif ($paidUntil !== null && $paidUntil->greaterThan($now)) {
            return false;
        } else {
            $trialEnds = $licence->trial_ends_at !== null ? CarbonImmutable::instance($licence->trial_ends_at) : null;
            $licence->expires_at = null;
            $licence->trial_ends_at = $trialEnds !== null && $trialEnds->greaterThan($end) ? $trialEnds : $end;
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
