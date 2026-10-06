<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\GoCardless\Support\SetupLink;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\BillingMailer;
use App\Domain\Billing\Support\CompanyPricing;
use App\Domain\Licensing\Actions\RenewCompanyLicences;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * billing:run step: trial emails, once each per trial end date. The company's trial ends when its first trial
 * licence does (the earliest `trial_ends_at` of a live, activated, never-paid licence of an active till).
 *
 * - TrialReminderMail from `billing.trial.reminder_days_before` (2) calendar days before that end.
 * - TrialEndedMail once it has passed, for trials that ended in the last `billing.trial.ended_window_days` (3).
 *
 * Tracked on the billing account (`trial_reminder_for` / `trial_ended_for` = the trial end they were for), so
 * a trial extended by staff gets its own reminder. Cancelled and suspended companies get nothing.
 */
class SendTrialEmails
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly BillingMailer $mailer,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @return array{reminders: int, ended: int}
     */
    public function handle(CarbonImmutable $now): array
    {
        $reminderDays = max(0, (int) config('billing.trial.reminder_days_before', 2));
        $windowDays = max(1, (int) config('billing.trial.ended_window_days', 3));
        $sent = ['reminders' => 0, 'ended' => 0];

        $trials = Licence::withoutCompanyScope()->live()
            ->whereNotNull('activated_at')->whereNull('expires_at')->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>', $now->subDays($windowDays))
            ->where('trial_ends_at', '<=', $now->addDays($reminderDays + 1))
            ->distinct()->pluck('company_id');

        foreach ($trials as $companyId) {
            $company = Company::query()->find($companyId);

            if ($company === null || in_array($company->status, [CompanyStatus::Cancelled, CompanyStatus::Suspended], true)) {
                continue;
            }

            $licences = RenewCompanyLicences::renewable($company)->get();
            $trialEnds = $licences->filter(fn (Licence $licence) => $licence->activated_at !== null && $licence->expires_at === null && $licence->trial_ends_at !== null)
                ->map(fn (Licence $licence) => $licence->trial_ends_at)->min();

            if ($trialEnds === null) {
                continue;
            }

            $daysLeft = (int) BillingDates::today($now)->diffInDays(BillingDates::localDate($trialEnds), false);
            $price = $this->priceSummary($company, $licences->first()?->plan);
            $account = $this->accounts->for($company);
            // Direct Debit customers without a working mandate get the setup link (module 1.12).
            $ddUrl = $account->isDirectDebit() && ! $account->hasUsableMandate() ? SetupLink::for($company) : null;

            DB::transaction(function () use ($company, $trialEnds, $now, $daysLeft, $reminderDays, $windowDays, $licences, $price, $ddUrl, &$sent) {
                $account = $this->accounts->lock($company);
                $sameEnd = fn (?CarbonImmutable $for) => $for !== null && $for->getTimestamp() === $trialEnds->getTimestamp();

                if ($now->lessThan($trialEnds) && $daysLeft <= $reminderDays && ! $sameEnd($account->trial_reminder_for)) {
                    $this->mailer->trialReminder($company, $trialEnds, max(0, $daysLeft), $licences->count(), $price, $ddUrl);
                    $account->trial_reminder_for = $trialEnds;
                    $account->trial_reminder_sent_at = $now;
                    $account->save();
                    $this->audit->handle('billing.trial_reminder_sent', $company, null, null, ['trial_ends_at' => $trialEnds->toIso8601String(), 'days_left' => $daysLeft], companyId: $company->id);
                    $sent['reminders']++;
                }

                if ($now->greaterThanOrEqualTo($trialEnds) && $now->lessThan($trialEnds->addDays($windowDays)) && ! $sameEnd($account->trial_ended_for)) {
                    $this->mailer->trialEnded($company, $trialEnds, $licences->count(), $price, $ddUrl);
                    $account->trial_ended_for = $trialEnds;
                    $account->trial_ended_sent_at = $now;
                    $account->save();
                    $this->audit->handle('billing.trial_ended_sent', $company, null, null, ['trial_ends_at' => $trialEnds->toIso8601String()], companyId: $company->id);
                    $sent['ended']++;
                }
            });
        }

        return $sent;
    }

    /** "£25.00 per till per month" (or per branch, per year): the company's pricing at its billing cycle. */
    private function priceSummary(Company $company, ?Plan $plan): ?string
    {
        $plan ??= DefaultPlan::for($company);

        if ($plan === null) {
            return null;
        }

        $account = $this->accounts->for($company);
        $pricing = CompanyPricing::for($company, $account);
        $cycle = $account->cycle;

        return BillingFormat::money($pricing->unitPrice($cycle, $plan)).' per '.$pricing->mode->unit().' '.$cycle->per();
    }
}
