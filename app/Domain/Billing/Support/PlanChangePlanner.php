<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Data\PlanChangePlan;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\GoCardless\Support\SubscriptionAmount;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Licensing\Actions\RenewCompanyLicences;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Works out what moving a business to another plan does (owner 2026-10-07, docs/billing-flow.md "Changing a business's
 * plan"). Reads only: the preview is built from it, and ChangeBusinessPlan applies the same plan.
 *
 * - The business's plan now: the plan its live tills are on, else the plan new tills get (CompanyPricing).
 * - Recurring fee: the new plan's prices (the business's own prices kept, except on a setup-only plan) for the tills it
 *   has. From setup only to a recurring plan, the first period starts today when tills hold a paid (setup-only) date
 *   and the setup fee is paid: those tills stay valid to the first period's end and that period is invoiced now
 *   (proration per `billing.generate.prorate`). Tills on a free trial keep it (the fee starts as for a new business).
 * - UK: a recurring fee is collected by Direct Debit; starting one without a usable mandate starts the mandate setup
 *   (deadline, banner, setup link) as for a new business. Manual collection (Pakistan): invoices paid by hand.
 * - Setup fee: PlanChangeSetupFee. Licences: PlanChangeLicences.
 */
final class PlanChangePlanner
{
    public function __construct(private readonly BillingAccounts $accounts) {}

    /**
     * @param  string|null  $setupFee  Net amount the admin typed; null = the suggested amount.
     */
    public function plan(Company $company, Plan $to, ?string $setupFee = null, ?CarbonImmutable $now = null): PlanChangePlan
    {
        $now ??= CarbonImmutable::now();
        $today = BillingDates::today($now);
        $account = $this->accounts->for($company);
        $licences = RenewCompanyLicences::renewable($company)->get();
        $from = CompanyPricing::for($company, $account, $licences)->plan;
        $manual = ManualCollection::active();
        $tills = SetupFeeTills::count($company);
        $setup = PlanChangeSetupFee::work($company, $account, $from, $to, $tills, $licences);
        $charge = $setup['kind'] === null ? null : ($setupFee !== null ? Money::normalise($setupFee) : $setup['suggested']);

        $ownPrices = $account->pricing_mode_override !== null || $account->price_monthly_override !== null || $account->price_yearly_override !== null;
        $clearsOwnPrices = $ownPrices && ! $to->billingType()->recurs();
        $oldRecurring = SubscriptionAmount::for($company, $account, $now)['gross'];
        $new = self::recurring($company, $account, $to, $licences, $today, $clearsOwnPrices);
        $oldRecurs = ! Money::isZero($oldRecurring);
        $newRecurs = ! Money::isZero($new['gross']);
        $startsRecurring = ! $oldRecurs && $newRecurs;
        $mandateUsable = $account->hasUsableMandate();
        $directDebit = ! $manual && ($account->isDirectDebit() || $startsRecurring);
        $startsMandateSetup = ! $manual && $startsRecurring && ! $mandateUsable;
        $deadline = $startsMandateSetup ? MandateDeadline::fromNow($now) : ($directDebit && $newRecurs && ! $mandateUsable ? $account->mandate_deadline_at : null);

        $state = SetupFeeState::for($company, $account);
        $newBusinessFee = $setup['kind'] === InvoiceKind::SetupFee;
        $settledAfter = $newBusinessFee ? $charge !== null && Money::isZero($charge) : $state->isSettled();
        $startedAfter = $newBusinessFee ? $settledAfter : $state->isStarted();

        $paid = Licence::withoutCompanyScope()->whereBelongsTo($company)->live()->where('expires_at', '>', $now)->orderBy('created_at')->get();
        $firstPeriodNow = $startsRecurring && $paid->isNotEmpty() && $startedAfter;
        $periodEnd = $account->cycle->periodEnd($today);
        $first = $firstPeriodNow ? self::firstPeriod($company, $account, $to, $licences, $paid->pluck('id')->all(), $today, $periodEnd) : null;

        return new PlanChangePlan(
            company: $company,
            account: $account,
            from: $from,
            to: $to,
            today: $today,
            samePlan: self::samePlan($company, $from, $to),
            manual: $manual,
            gained: self::diff($to->features, $from?->features),
            lost: self::diff($from->features ?? collect(), $to->features),
            customShops: self::customShops($company),
            followingShops: Branch::withoutCompanyScope()->where('company_id', $company->id)->where('is_active', true)->whereNull('licence_features')->count(),
            tills: $tills,
            coveredTills: $setup['covered'],
            uncoveredTills: $setup['uncovered'],
            perTill: $setup['perTill'],
            suggestedSetupFee: $setup['suggested'],
            setupFee: $charge,
            setupKind: $setup['kind'],
            setupLicences: $setup['licences'],
            vatRate: Vat::rateFor($account),
            setupSettledAfter: $settledAfter,
            cycle: $account->cycle,
            oldRecurring: $oldRecurring,
            newRecurring: $new['gross'],
            newUnitPrice: $new['unitPrice'],
            unitLabel: $new['unit'],
            oldRecurs: $oldRecurs,
            newRecurs: $newRecurs,
            keepsOwnPrices: $ownPrices && ! $clearsOwnPrices,
            clearsOwnPrices: $clearsOwnPrices,
            directDebit: $directDebit,
            switchesToDirectDebit: $directDebit && ! $account->isDirectDebit(),
            mandateUsable: $mandateUsable,
            startsMandateSetup: $startsMandateSetup,
            mandateDeadlineAt: $deadline,
            liveSubscription: $account->hasLiveSubscription(),
            nextPeriodStart: BillingPeriod::nextStart($company, $now, $licences),
            firstPeriodNow: $firstPeriodNow,
            periodEnd: $firstPeriodNow ? $periodEnd : null,
            firstPeriodGross: $first['gross'] ?? null,
            firstPeriodTills: $first['units'] ?? 0,
            firstPeriodDue: $firstPeriodNow ? self::firstDue($manual, $today, $deadline) : null,
            paidLicences: $firstPeriodNow ? $paid->all() : [],
            fullTermNow: $to->billingType() === PlanBillingType::SetupOnly && $settledAfter,
            fullTermUntil: $to->billingType() === PlanBillingType::SetupOnly ? $today->addYears(max(1, (int) config('billing.setup_only.years', 10))) : null,
            oldGraceDays: $from->grace_days ?? $to->grace_days,
            newGraceDays: $to->grace_days,
            liveLicences: Licence::withoutCompanyScope()->whereBelongsTo($company)->live()->count(),
            openSetupInvoices: PlanChangeSetupFee::open($company),
            openPeriodInvoices: Invoice::withoutCompanyScope()->where('company_id', $company->id)->forPeriods()->open()
                ->orderBy('period_start')->get()->all(),
        );
    }

    /** Due date of the first period invoice: by hand, the manual due days; Direct Debit, the mandate deadline (if any). */
    private static function firstDue(bool $manual, CarbonImmutable $today, ?CarbonImmutable $deadline): ?CarbonImmutable
    {
        if ($manual) {
            return $today->addDays(ManualCollection::dueDays());
        }

        return $deadline !== null ? BillingDates::localDate($deadline) : null;
    }

    /**
     * The new plan's full-period amount for the tills the business has (as SubscriptionAmount, on the new plan).
     *
     * @param  Collection<int, Licence>  $licences
     * @return array{gross: string, unitPrice: string|null, unit: string}
     */
    private static function recurring(Company $company, BillingAccount $account, Plan $to, Collection $licences, CarbonImmutable $start, bool $clearOwnPrices): array
    {
        $account = clone $account;

        if ($clearOwnPrices) {
            $account->pricing_mode_override = null;
            $account->price_monthly_override = null;
            $account->price_yearly_override = null;
        }

        $simulated = self::onPlan($licences, $to);
        $pricing = CompanyPricing::for($company, $account, $simulated);
        $lines = InvoiceLineBuilder::build($simulated, $start, $account->cycle->periodEnd($start), $account->cycle, Vat::rateFor($account), false, $pricing);
        $prices = array_values(array_unique(array_column($lines, 'unit_price')));

        return [
            'gross' => InvoiceMaths::totals($lines)['total'],
            'unitPrice' => count($prices) === 1 ? $prices[0] : ($prices === [] ? $pricing->unitPrice($account->cycle) : null),
            'unit' => $pricing->mode->unit(),
        ];
    }

    /**
     * The first period invoice's total (as GenerateInvoice will make it): the paid tills' setup-only dates end the day
     * before, so proration (when on) charges every paid till in full and a trial till only after its trial.
     *
     * @param  Collection<int, Licence>  $licences
     * @param  list<string>  $paidIds
     * @return array{gross: string, units: int}
     */
    private static function firstPeriod(Company $company, BillingAccount $account, Plan $to, Collection $licences, array $paidIds, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $cut = BillingDates::endOfDay($start->subDay());
        $simulated = self::onPlan($licences, $to)->map(function (Licence $licence) use ($paidIds, $cut) {
            if (in_array($licence->id, $paidIds, true)) {
                $licence->expires_at = $cut;
            }

            return $licence;
        });
        $prorate = (bool) config('billing.generate.prorate', false);
        $lines = InvoiceLineBuilder::build($simulated, $start, $end, $account->cycle, Vat::rateFor($account), $prorate, CompanyPricing::for($company, $account, $simulated));

        return ['gross' => InvoiceMaths::totals($lines)['total'], 'units' => count($lines)];
    }

    /**
     * Copies of the licences on another plan (nothing saved).
     *
     * @param  Collection<int, Licence>  $licences
     * @return Collection<int, Licence>
     */
    private static function onPlan(Collection $licences, Plan $plan): Collection
    {
        return $licences->map(function (Licence $licence) use ($plan) {
            $copy = clone $licence;
            $copy->plan_id = $plan->id;

            return $copy->setRelation('plan', $plan);
        });
    }

    private static function samePlan(Company $company, ?Plan $from, Plan $to): bool
    {
        return $from?->id === $to->id && in_array($company->plan_id, [null, $to->id], true)
            && ! Licence::withoutCompanyScope()->whereBelongsTo($company)->live()->where('plan_id', '!=', $to->id)->exists();
    }

    /**
     * Features in `$a` and not in `$b`, in the till's order.
     *
     * @param  iterable<Feature>  $a
     * @param  iterable<Feature>|null  $b
     * @return list<Feature>
     */
    private static function diff(iterable $a, ?iterable $b): array
    {
        $others = Feature::normalise($b ?? []);

        return array_values(array_filter(Feature::normalise($a), fn (Feature $feature) => ! in_array($feature, $others, true)));
    }

    /**
     * Active shops with their own features: they keep them.
     *
     * @return list<array{name: string, features: list<Feature>}>
     */
    private static function customShops(Company $company): array
    {
        return Branch::withoutCompanyScope()->where('company_id', $company->id)->where('is_active', true)->whereNotNull('licence_features')
            ->orderBy('name')->get()
            ->map(fn (Branch $branch) => ['name' => $branch->name, 'features' => Feature::normalise($branch->licence_features ?? [])])
            ->values()->all();
    }
}
