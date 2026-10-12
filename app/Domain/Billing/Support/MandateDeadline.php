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
 *
 * Audit BILL-1 (2026-10-11): a deadline only counts once the customer was told about it. One that passed while nothing
 * recurred (a setup-only business keeps its onboarding deadline) was never reminded nor suspended for: it is
 * `unwarned`, never `missed`, and billing:run starts a fresh one (EnforceDirectDebit). When something starts to recur,
 * StartMandateDeadline starts a fresh one at once.
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

    /**
     * A mandate is needed and the deadline has passed (the business is, or is about to be, suspended). A deadline
     * nobody was warned of does not count (BILL-1).
     */
    public static function missed(Company $company, BillingAccount $account, ?CarbonImmutable $now = null): bool
    {
        return self::applies($company, $account) && $account->mandate_deadline_at !== null
            && $account->mandate_deadline_at->lessThanOrEqualTo($now ?? CarbonImmutable::now())
            && ! self::unwarned($account, $now);
    }

    /**
     * BILL-1: the deadline has passed, but no reminder was sent for it and nobody was suspended for it, so it ran out
     * while nothing recurred (or billing:run never saw it recur). It must not suspend: a fresh deadline starts instead.
     * A business already suspended for its deadline is never unwarned (`mandate_grace_suspended_for`).
     */
    public static function unwarned(BillingAccount $account, ?CarbonImmutable $now = null): bool
    {
        $deadline = $account->mandate_deadline_at;

        return $deadline !== null && $deadline->lessThanOrEqualTo($now ?? CarbonImmutable::now())
            && $account->mandate_reminder_for?->getTimestamp() !== $deadline->getTimestamp()
            && $account->mandate_grace_suspended_for?->getTimestamp() !== $deadline->getTimestamp();
    }

    /**
     * BILL-1: a fresh deadline (now + the deadline days) and the reminder due again; never an earlier deadline.
     * Returns false when the current one is already as late. The caller saves.
     */
    public static function restart(BillingAccount $account, ?CarbonImmutable $now = null): bool
    {
        $fresh = self::fromNow($now);

        if ($account->mandate_deadline_at !== null && $account->mandate_deadline_at->greaterThanOrEqualTo($fresh)) {
            return false;
        }

        $account->mandate_deadline_at = $fresh;
        $account->mandate_reminder_for = null;

        return true;
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
