<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\GoCardless\Support\SubscriptionAmount;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\MandateDeadline;
use App\Domain\Billing\Support\ManualCollection;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Audit BILL-1 (2026-10-11): a Direct Debit business without a working mandate whose recurring amount goes from £0
 * to more than £0 (its own price, a cycle change, a till on a recurring plan) gets a fresh deadline from now
 * (`billing.direct_debit.mandate_deadline_days`) with the reminder due again, as Change plan does. Without it, the
 * onboarding deadline of a setup-only business, long passed, would suspend it at the next billing:run with no warning.
 *
 * Usage: `$before = before($company)` ahead of the change, `after($company, $before, $reason)` once it is saved (it
 * takes the billing lock itself). Only moves a deadline later; never sets one where none exists; audited
 * `billing.dd_mandate_deadline_restarted`. Pakistan (manual collection) has no deadline: nothing happens there.
 */
class StartMandateDeadline
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly RecordAudit $audit,
    ) {}

    /** What recurs each cycle now (gross), or null when no deadline could need a restart (nothing to compare). */
    public function before(Company $company): ?string
    {
        if (ManualCollection::active() || $company->isCancelled()) {
            return null;
        }

        $account = $this->accounts->for($company);

        if (! $account->isDirectDebit() || $account->hasUsableMandate() || $account->mandate_deadline_at === null) {
            return null;
        }

        return SubscriptionAmount::for($company, $account)['gross'];
    }

    /** After the change: a fresh deadline when nothing recurred before and something does now. */
    public function after(Company $company, ?string $before, string $reason, ?CarbonImmutable $now = null): bool
    {
        if ($before === null || ! Money::isZero($before)) {
            return false;
        }

        return DB::transaction(function () use ($company, $reason, $now) {
            $account = $this->accounts->lock($company);

            return MandateDeadline::applies($company, $account) && $this->restartLocked($company, $account, $reason, $now);
        });
    }

    /** With the billing lock held: the fresh deadline, saved and audited. False when the current one is as late. */
    public function restartLocked(Company $company, BillingAccount $account, string $reason, ?CarbonImmutable $now = null): bool
    {
        $before = $account->mandate_deadline_at;

        if (! MandateDeadline::restart($account, $now)) {
            return false;
        }

        $account->save();
        $this->audit->handle('billing.dd_mandate_deadline_restarted', $account, [
            'deadline' => $before?->toIso8601String(),
        ], [
            'deadline' => $account->mandate_deadline_at?->toIso8601String(),
        ], ['reason' => $reason], companyId: $company->id);

        return true;
    }
}
