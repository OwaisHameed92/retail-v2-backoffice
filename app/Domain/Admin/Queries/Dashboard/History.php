<?php

namespace App\Domain\Admin\Queries\Dashboard;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * "Was this row counted at time T?" for the dashboard series, from the rows' own timestamps (we keep no daily
 * snapshots). At T = now the answers match the current state; earlier points are the best reading of the
 * timestamps (e.g. a till released and bound again counts from its latest binding).
 */
final class History
{
    /** The company existed, was not cancelled or deleted, and was not suspended at `$at`. */
    public static function companyLive(?Company $company, CarbonImmutable $at): bool
    {
        if ($company === null || self::after($company->created_at, $at) || self::by($company->deleted_at, $at)) {
            return false;
        }

        if ($company->status === CompanyStatus::Cancelled && ($company->cancelled_at === null || self::by($company->cancelled_at, $at))) {
            return false;
        }

        return ! ($company->status === CompanyStatus::Suspended && ($company->suspended_at === null || self::by($company->suspended_at, $at)));
    }

    /** Not revoked, deleted or suspended at `$at`. */
    public static function licenceLive(Licence $licence, CarbonImmutable $at): bool
    {
        if (self::by($licence->deleted_at, $at) || self::by($licence->revoked_at, $at)) {
            return false;
        }

        if ($licence->status === LicenceStatus::Revoked && $licence->revoked_at === null) {
            return false;
        }

        return ! ($licence->status === LicenceStatus::Suspended && ($licence->suspended_at === null || self::by($licence->suspended_at, $at)));
    }

    /** An active till: bound to a PC, live, and not past its grace at `$at`. */
    public static function activeTill(Licence $licence, CarbonImmutable $at, CarbonImmutable $now): bool
    {
        if ($licence->bound_at === null || self::after($licence->bound_at, $at) || ! self::licenceLive($licence, $at)) {
            return false;
        }

        if ($at->equalTo($now) && ($licence->device_id === null || $licence->status === LicenceStatus::Expired)) {
            return false;
        }

        return ! self::by($licence->grace_ends_at, $at);
    }

    /**
     * On a free trial at `$at`: activated, trial not over, not paid (at now; earlier points cannot tell when a
     * trial was paid, so a till paid for during its trial counts as trial until the trial end).
     */
    public static function trial(Licence $licence, CarbonImmutable $at, CarbonImmutable $now): bool
    {
        if ($licence->activated_at === null || $licence->trial_ends_at === null || self::after($licence->activated_at, $at)) {
            return false;
        }

        if (self::by($licence->trial_ends_at, $at) || ! self::licenceLive($licence, $at)) {
            return false;
        }

        return ! ($at->equalTo($now) && $licence->expires_at !== null);
    }

    /** Overdue and unpaid at `$at`. Now: exactly the invoices in status overdue. */
    public static function overdue(Invoice $invoice, CarbonImmutable $at, CarbonImmutable $now): bool
    {
        if ($at->equalTo($now)) {
            return $invoice->status === InvoiceStatus::Overdue;
        }

        return $invoice->overdue_at !== null && ! self::after($invoice->overdue_at, $at)
            && ! self::by($invoice->paid_at, $at) && ! self::by($invoice->voided_at, $at);
    }

    /** What was owed on an overdue invoice: its balance while open, its total once settled. */
    public static function overdueAmount(Invoice $invoice): string
    {
        return $invoice->status->isOpen() ? $invoice->balance : $invoice->total;
    }

    /** `$moment` is set and at or before `$at`. */
    private static function by(?CarbonInterface $moment, CarbonImmutable $at): bool
    {
        return $moment !== null && $moment->lessThanOrEqualTo($at);
    }

    private static function after(?CarbonInterface $moment, CarbonImmutable $at): bool
    {
        return $moment !== null && $moment->greaterThan($at);
    }
}
