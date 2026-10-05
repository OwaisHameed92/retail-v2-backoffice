<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\GoCardless\Support\SubscriptionAmount;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * The Direct Debit deadline (module 1.13, owner rules 2026-10-05): a Direct Debit business must have a working
 * mandate within `billing.direct_debit.mandate_deadline_days` (3) of going live, or billing:run suspends it until it
 * has one. A lost (cancelled, failed…) mandate is treated the same: ApplyMandate starts a new deadline of
 * `mandate_grace_days`. Not needed while nothing recurs (£0 a cycle, e.g. a setup-only plan).
 */
final class MandateDeadline
{
    public static function days(): int
    {
        return max(0, (int) config('billing.direct_debit.mandate_deadline_days', 3));
    }

    public static function fromNow(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->addDays(self::days());
    }

    /** Still waiting for the customer's first mandate, with something to collect. */
    public static function applies(Company $company, BillingAccount $account): bool
    {
        return $account->isDirectDebit()
            && ! $account->hasUsableMandate()
            && $account->mandate_deadline_at !== null
            && ! $company->isCancelled()
            && ! Money::isZero(SubscriptionAmount::for($company, $account)['gross']);
    }

    /** A mandate is needed and the deadline has passed (the business is, or is about to be, suspended). */
    public static function missed(Company $company, BillingAccount $account, ?CarbonImmutable $now = null): bool
    {
        return self::applies($company, $account) && $account->mandate_deadline_at !== null
            && $account->mandate_deadline_at->lessThanOrEqualTo($now ?? CarbonImmutable::now());
    }

    /** The suspension reason: never set up, or set up and then stopped. */
    public static function reason(BillingAccount $account): string
    {
        return $account->gc_mandate_lost_at !== null
            ? 'Direct Debit '.mb_strtolower($account->gc_mandate_status?->label() ?? 'cancelled').' and not replaced'
            : 'No Direct Debit set up';
    }

    /**
     * For the portal banner and billing page: null when no mandate is needed.
     *
     * @return array{deadline: string, daysLeft: int, passed: bool}|null
     */
    public static function state(Company $company, BillingAccount $account, ?CarbonImmutable $now = null): ?array
    {
        if (! self::applies($company, $account) || $account->mandate_deadline_at === null) {
            return null;
        }

        $now ??= CarbonImmutable::now();
        $deadline = $account->mandate_deadline_at;
        $hours = $now->diffInHours($deadline, false);

        return [
            'deadline' => $deadline->toIso8601String(),
            'daysLeft' => $hours <= 0 ? 0 : (int) ceil($hours / 24),
            'passed' => $hours <= 0,
        ];
    }
}
