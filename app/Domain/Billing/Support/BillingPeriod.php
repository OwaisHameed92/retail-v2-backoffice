<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Licensing\Actions\RenewCompanyLicences;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Which period a company's next invoice covers.
 *
 * The anchor is when the company's tills run out now: the latest paid expiry when any till is paid (renewals
 * keep a company's tills in step), else the earliest trial end (so no till lapses before the first invoice).
 * The next period starts the day after the anchor (London) while it is still ahead, else today, and runs one
 * billing cycle (BillingCycle::periodEnd).
 */
final class BillingPeriod
{
    /**
     * @param  Collection<int, Licence>|null  $licences  Renewable licences, when the caller already has them.
     */
    public static function anchor(Company $company, ?Collection $licences = null): ?CarbonImmutable
    {
        $licences ??= RenewCompanyLicences::renewable($company)->get();

        $paid = $licences->map(fn (Licence $licence) => $licence->expires_at)->filter();

        if ($paid->isNotEmpty()) {
            return $paid->max();
        }

        return $licences->map(fn (Licence $licence) => $licence->trial_ends_at)->filter()->min();
    }

    /**
     * @param  Collection<int, Licence>|null  $licences
     */
    public static function nextStart(Company $company, CarbonImmutable $now, ?Collection $licences = null): CarbonImmutable
    {
        $anchor = self::anchor($company, $licences);

        return $anchor !== null && $anchor->greaterThan($now)
            ? BillingDates::londonDate($anchor)->addDay()
            : BillingDates::today($now);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function next(Company $company, BillingCycle $cycle, CarbonImmutable $now): array
    {
        $start = self::nextStart($company, $now);

        return [$start, $cycle->periodEnd($start)];
    }
}
