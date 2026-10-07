<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Data\PlanChangePlan;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\GoCardless\Actions\SyncSubscription;
use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\PlanChangeLicences;
use App\Domain\Billing\Support\PlanChangeMailer;
use App\Domain\Billing\Support\PlanChangePlanner;
use App\Domain\Billing\Support\PlanChangeSetupCharge;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Moves an existing business to another plan (owner 2026-10-07; docs/billing-flow.md "Changing a business's plan").
 * Only an admin with `billing.manage` calls it, after the preview (PlanChangePlanner, the same rules):
 *
 * 1. In one transaction: the business and every live licence go onto the plan (PlanChangeLicences: features, a shop's
 *    own features kept, grace days); its own prices are cleared on a setup-only plan; a setup fee for tills not
 *    covered yet is invoiced (PlanChangeSetupCharge; the admin's amount, 0 waives it); from setup only to a recurring
 *    plan the first period is invoiced from today and paid tills stay valid to its end; on the UK the business is put
 *    on Direct Debit with a new mandate deadline when it has no mandate. Audited `billing.plan_changed`.
 * 2. Then: a setup-only plan gets its terms (ApplySetupFeeTerms: the full licence once the setup fee is settled); the
 *    UK Direct Debit subscription follows (SyncSubscription: created, amount changed or cancelled; demo businesses are
 *    never sent); the owners get "Your plan has changed".
 *
 * Returns the invoices made and, when GoCardless could not be reached, a warning (the daily reconcile retries).
 *
 * @phpstan-type Result array{setupInvoice: Invoice|null, firstInvoice: Invoice|null, licences: int, subscription: string|null, warning: string|null}
 */
class ChangeBusinessPlan
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly PlanChangePlanner $planner,
        private readonly PlanChangeLicences $licences,
        private readonly PlanChangeSetupCharge $setupCharge,
        private readonly GenerateInvoice $generateInvoice,
        private readonly IssueInvoice $issueInvoice,
        private readonly ApplySetupFeeTerms $setupFeeTerms,
        private readonly SyncSubscription $syncSubscription,
        private readonly PlanChangeMailer $mailer,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  string|null  $setupFee  Net setup fee the admin entered; null = the suggested amount; "0" waives it.
     * @return Result
     *
     * @throws ValidationException
     */
    public function handle(Company $company, Plan $to, ?string $setupFee = null): array
    {
        $now = CarbonImmutable::now();
        $company->refresh();
        $plan = $this->check($company, $to, $setupFee, $now);
        $firstDue = $plan->firstPeriodNow && ! $plan->manual && $plan->mandateUsable ? $this->firstCollection($plan) : $plan->firstPeriodDue;

        [$setupInvoice, $firstInvoice, $moved] = DB::transaction(function () use ($company, $to, $plan, $now, $firstDue) {
            $account = $this->accounts->lock($company);
            $company->plan_id = $to->id;
            $company->save();
            $moved = $this->licences->move($company, $plan->from, $to, $now);

            if ($plan->clearsOwnPrices) {
                $account->pricing_mode_override = null;
                $account->price_monthly_override = null;
                $account->price_yearly_override = null;
            }

            if ($plan->switchesToDirectDebit) {
                $account->billing_mode = BillingMode::DirectDebit;
            }

            if ($plan->startsMandateSetup) {
                $account->mandate_deadline_at = $plan->mandateDeadlineAt;
                $account->mandate_reminder_for = null;
            }

            $account->recurring_starts_on = $plan->firstPeriodNow && ! $plan->manual ? $plan->today : ($plan->newRecurs ? $account->recurring_starts_on : null);
            $draft = $this->setupCharge->handle($plan, $account);
            $account->save();

            $setupInvoice = $draft !== null ? $this->issueInvoice->handle($draft, send: true) : null;
            $first = $plan->firstPeriodNow ? $this->firstPeriod($company, $plan, $firstDue, $now) : null;
            $this->record($plan, $setupInvoice, $first, $moved);

            return [$setupInvoice, $first, $moved];
        });

        if ($to->billingType() === PlanBillingType::SetupOnly) {
            $this->setupFeeTerms->handle($company->refresh(), now: $now);
        }

        [$subscription, $warning] = $this->syncDirectDebit($company->refresh(), $plan);
        $this->mailer->send($plan, $setupInvoice, $firstInvoice);

        return ['setupInvoice' => $setupInvoice, 'firstInvoice' => $firstInvoice, 'licences' => $moved, 'subscription' => $subscription, 'warning' => $warning];
    }

    /** @throws ValidationException */
    private function check(Company $company, Plan $to, ?string $setupFee, CarbonImmutable $now): PlanChangePlan
    {
        if (! DefaultPlan::isOffered($to)) {
            throw ValidationException::withMessages(['plan_id' => "{$to->name} is not offered any more. Choose an active plan."]);
        }

        if ($company->isCancelled() || $company->trashed()) {
            throw ValidationException::withMessages(['plan_id' => "{$company->name} is cancelled. Its plan cannot be changed."]);
        }

        if ($setupFee !== null && Money::isNegative($setupFee)) {
            throw ValidationException::withMessages(['setup_fee' => 'The setup fee cannot be below 0.']);
        }

        $plan = $this->planner->plan($company, $to, $setupFee, $now);

        if ($plan->samePlan) {
            throw ValidationException::withMessages(['plan_id' => "{$company->name} is already on {$to->name}."]);
        }

        return $plan;
    }

    /**
     * The first period, invoiced from today: the paid tills' setup-only dates end yesterday while it is made (so it
     * charges them in full, with proration as configured), then they stay valid to the period end.
     */
    private function firstPeriod(Company $company, PlanChangePlan $plan, ?CarbonImmutable $due, CarbonImmutable $now): Invoice
    {
        $before = $this->licences->endBefore($plan->paidLicences, $plan->today);

        $invoice = $this->generateInvoice->handle($company, new NewInvoice(
            periodStart: $plan->today,
            notes: "First period on {$plan->to->name}.",
            issue: true,
            allowOverlap: true,
            dueDate: $due,
            // By hand: emailed now, as manual billing does. Direct Debit: emailed once a payment collects it.
            send: $plan->manual,
        ));

        $this->licences->bridge($company, $before, $plan->periodEnd ?? $plan->today, $now);

        return $invoice;
    }

    /** The first Direct Debit collection on the mandate the business has: today, or the mandate's first possible day. */
    private function firstCollection(PlanChangePlan $plan): ?CarbonImmutable
    {
        try {
            $earliest = app(GoCardlessClient::class)->mandate((string) $plan->account->gc_mandate_id)->nextPossibleChargeDate;
        } catch (GoCardlessException) {
            return null; // demo business or GoCardless unreachable: the payment terms apply
        }

        $day = $earliest !== null ? BillingDates::date($earliest) : $plan->today;

        return $day->greaterThan($plan->today) ? $day : $plan->today;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function syncDirectDebit(Company $company, PlanChangePlan $plan): array
    {
        if ($plan->manual || ! $this->accounts->for($company)->isDirectDebit()) {
            return [null, null];
        }

        try {
            return [$this->syncSubscription->handle($company, 'planChange'), null];
        } catch (GoCardlessException $exception) {
            Log::warning('Direct Debit subscription not updated after a plan change', ['company_id' => $company->id, 'error' => $exception->getMessage()]);

            return [null, 'GoCardless could not be updated just now. The daily reconcile puts the Direct Debit right, or use Sync on the Direct Debit card.'];
        }
    }

    private function record(PlanChangePlan $plan, ?Invoice $setupInvoice, ?Invoice $first, int $moved): void
    {
        $features = fn (iterable $list) => array_map(fn (Feature $f) => $f->value, Feature::normalise($list));

        $this->audit->handle('billing.plan_changed', $plan->company, [
            'plan' => $plan->from?->code,
            'billing_type' => $plan->from?->billingType()->value,
            'features' => $features($plan->from->features ?? []),
            'recurring' => $plan->oldRecurring,
            'covered_tills' => $plan->coveredTills,
        ], [
            'plan' => $plan->to->code,
            'billing_type' => $plan->to->billingType()->value,
            'features' => $features($plan->to->features),
            'recurring' => $plan->newRecurring,
            'setup_fee' => $plan->setupKind !== null ? $plan->setupFee : null,
            'setup_invoice' => $setupInvoice?->number,
            'first_invoice' => $first?->number,
            'mandate_deadline_at' => $plan->startsMandateSetup ? $plan->mandateDeadlineAt?->toIso8601String() : null,
        ], [
            'from_plan_name' => $plan->from?->name,
            'to_plan_name' => $plan->to->name,
            'licences' => $moved,
            'custom_shops' => array_map(fn (array $shop) => $shop['name'], $plan->customShops),
            'suggested_setup_fee' => $plan->suggestedSetupFee,
        ], companyId: $plan->company->id);
    }
}
