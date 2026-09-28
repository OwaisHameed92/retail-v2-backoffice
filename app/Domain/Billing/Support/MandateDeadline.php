<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\GoCardless\Support\SubscriptionAmount;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * The Direct Debit deadline (module 1.13): a Direct Debit business must have a working mandate within
 * `billing.direct_debit.mandate_deadline_days` (3) of onboarding, or billing:run suspends it until it has one. Not
 * needed while nothing recurs (£0 a cycle), nor once a mandate exists (a lost mandate has its own grace rule).
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
            && $account->gc_mandate_lost_at === null
            && $account->mandate_deadline_at !== null
            && ! $company->isCancelled()
            && ! Money::isZero(SubscriptionAmount::for($company, $account)['gross']);
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
