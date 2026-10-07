<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\ManualCollection;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Money;

/**
 * The sentences of the "Change plan" preview, one list per part (PlanChangePreview). Admin wording; a Pakistan
 * (manual collection) instance never mentions Direct Debit.
 */
final class PlanChangeWords
{
    /** How a setup fee is paid by hand on this instance. */
    public static function byHand(PlanChangePlan $plan): string
    {
        return $plan->manual ? ManualCollection::methodsText() : 'cash, card or bank transfer';
    }

    /**
     * @return list<string>
     */
    public static function features(PlanChangePlan $plan): array
    {
        $lines = [];
        $names = fn (array $features) => implode(', ', array_map(fn (Feature $f) => $f->label(), $features));

        if ($plan->gained === [] && $plan->lost === []) {
            $lines[] = 'The same features as now.';
        }

        if ($plan->gained !== []) {
            $lines[] = 'Gains: '.$names($plan->gained).'.';
        }

        if ($plan->lost !== []) {
            $lines[] = 'Loses: '.$names($plan->lost).'.';
        }

        if ($plan->followingShops > 0 && $plan->customShops !== []) {
            $lines[] = ($plan->followingShops === 1 ? '1 shop follows' : "{$plan->followingShops} shops follow")." the plan and gets {$plan->to->name}’s features.";
        }

        foreach ($plan->customShops as $shop) {
            $lines[] = "{$shop['name']} keeps its own features".($shop['features'] === [] ? ' (none)' : ' ('.$names($shop['features']).')').'.';
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    public static function setupFee(PlanChangePlan $plan): array
    {
        $fee = BillingFormat::money($plan->to->setup_fee);
        $tills = fn (int $n) => $n === 1 ? '1 till' : "{$n} tills";

        if ($plan->setupKind === null) {
            return match (true) {
                ! $plan->to->billingType()->hasSetupFee() || Money::isZero($plan->to->setup_fee) => ["{$plan->to->name} has no setup fee."],
                $plan->coveredTills > 0 && $plan->uncoveredTills > 0 && ! $plan->perTill => ['Nothing to charge: the business already paid its setup fee ('.$plan->to->name.' charges it once per business).'],
                default => ['Nothing to charge: the setup fee already paid covers '.($plan->tills === 1 ? 'the till' : "all {$plan->tills} tills").'.'],
            };
        }

        $lines = $plan->setupKind === InvoiceKind::SetupFee
            ? ['No setup fee has been paid yet, so '.$plan->to->name.'’s setup fee is charged'.($plan->perTill ? " for each till: {$fee} × ".$tills($plan->tills).'.' : ": {$fee}.")]
            : [
                "{$plan->coveredTills} of {$plan->tills} tills are covered by the setup fee already paid: they are never charged again.",
                'Not covered yet: '.PlanChangePreview::tillNames($plan->setupLicences).', '.BillingFormat::money($plan->account->till_setup_fee_override ?? $plan->to->setup_fee).' each.',
            ];

        if ($plan->chargesSetupFee()) {
            $lines[] = 'A setup fee invoice for '.BillingFormat::money(PlanChangePreview::gross((string) $plan->setupFee, $plan->vatRate)).' is issued and emailed now, paid by hand ('.self::byHand($plan).').';
        } else {
            $lines[] = $plan->setupKind === InvoiceKind::SetupFee
                ? 'Waived: no invoice; the setup fee is recorded as nothing to pay.'
                : 'Waived: no invoice; those tills are marked as covered.';
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    public static function recurring(PlanChangePlan $plan): array
    {
        $per = $plan->cycle->per();
        $new = BillingFormat::money($plan->newRecurring).' '.$per;
        $tax = Money::isZero($plan->vatRate) ? '' : ' + '.app(Country::class)->taxName();
        $unit = $plan->newUnitPrice !== null ? ' ('.BillingFormat::money($plan->newUnitPrice).' per '.$plan->unitLabel.$tax.')' : '';

        if (! $plan->oldRecurs && ! $plan->newRecurs) {
            return ['Nothing recurring, as now.'];
        }

        if ($plan->stopsRecurring()) {
            return array_values(array_filter([
                'The fee of '.BillingFormat::money($plan->oldRecurring).' '.$per.' stops.',
                $plan->liveSubscription ? 'The Direct Debit subscription is cancelled. A payment already on its way is still collected.' : null,
                $plan->clearsOwnPrices ? 'The business’s own prices (Change pricing) are cleared: nothing recurs on '.$plan->to->name.'.' : null,
            ]));
        }

        $lines = $plan->startsRecurring() ? self::starts($plan, $new.$unit) : [
            Money::equals($plan->oldRecurring, $plan->newRecurring)
                ? "Stays {$new}{$unit}."
                : 'Changes from '.BillingFormat::money($plan->oldRecurring)." to {$new}{$unit} from the next period (starting ".PlanChangePreview::day($plan->nextPeriodStart).'). Time already paid is not charged again.',
        ];

        if ($plan->keepsOwnPrices) {
            $lines[] = 'The business keeps its own prices (Change pricing).';
        }

        return [...$lines, ...self::collection($plan)];
    }

    /**
     * @return list<string>
     */
    private static function starts(PlanChangePlan $plan, string $amount): array
    {
        if ($plan->firstPeriodNow && $plan->periodEnd !== null) {
            return ["{$amount} starts today. The first period is ".BillingDates::range($plan->today, $plan->periodEnd)
                .' ('.BillingFormat::money((string) $plan->firstPeriodGross).' for '.($plan->firstPeriodTills === 1 ? '1 till' : "{$plan->firstPeriodTills} tills").').'];
        }

        return ["{$amount} starts as for a new business: once the setup fee is paid and any free trial has ended."];
    }

    /**
     * @return list<string>
     */
    private static function collection(PlanChangePlan $plan): array
    {
        if ($plan->manual) {
            return $plan->firstPeriodNow
                ? ['An invoice each period, paid by hand ('.self::byHand($plan).'). The next one is issued 7 days before '.PlanChangePreview::day($plan->periodEnd).'.']
                : ['An invoice each period, paid by hand ('.self::byHand($plan).').'];
        }

        if (! $plan->directDebit) {
            return ['Invoiced by hand, as now (this business does not pay by Direct Debit).'];
        }

        $lines = $plan->switchesToDirectDebit ? ['The business is switched to Direct Debit.'] : [];

        if ($plan->mandateUsable) {
            $lines[] = match (true) {
                $plan->firstPeriodNow => 'Collected by Direct Debit on the mandate it has: the first payment on the first day GoCardless allows, then every '.($plan->cycle->value === 'yearly' ? 'year' : 'month').'.',
                $plan->liveSubscription => 'The Direct Debit amount changes from the next payment GoCardless has not created yet.',
                default => 'Collected by Direct Debit on the mandate it has.',
            };

            return $lines;
        }

        $deadline = $plan->mandateDeadlineAt !== null ? BillingDates::long(BillingDates::localDate($plan->mandateDeadlineAt)) : null;
        $lines[] = $plan->startsMandateSetup
            ? 'A Direct Debit is needed: the owners see “Set up your Direct Debit” in the portal and the link in the email. Deadline '.$deadline.'. Not suspended before then; without a Direct Debit by then the account is suspended.'
            : 'Collected by Direct Debit once the mandate is set up'.($deadline !== null ? " (deadline {$deadline})." : '.');

        return $lines;
    }

    /**
     * @return list<string>
     */
    public static function licences(PlanChangePlan $plan): array
    {
        $lines = [($plan->liveLicences === 1 ? '1 till licence moves' : "{$plan->liveLicences} till licences move")." to {$plan->to->name}. Each till picks up its new features and dates at its next check-in."];

        if ($plan->firstPeriodNow && $plan->periodEnd !== null) {
            $lines[] = 'Tills paid by the setup fee stay valid to '.PlanChangePreview::day($plan->periodEnd).', then renew as each period is paid. No till locks at the change.';
        } elseif ($plan->to->billingType()->recurs() === false) {
            $lines[] = $plan->fullTermNow
                ? 'Every till gets the full licence to '.PlanChangePreview::day($plan->fullTermUntil).' at once.'
                : 'Tills keep their current dates until the setup fee is paid, then get the full licence for '.(int) config('billing.setup_only.years', 10).' years.';
        } else {
            $lines[] = 'Paid-until dates do not change.';
        }

        if ($plan->chargesSetupFee() && $plan->setupKind === InvoiceKind::TillSetupFee) {
            $lines[] = PlanChangePreview::tillNames($plan->setupLicences).' keep their current dates until their setup fee invoice is paid.';
        }

        if ($plan->oldGraceDays !== $plan->newGraceDays) {
            $lines[] = "Grace after the paid date: {$plan->newGraceDays} days (was {$plan->oldGraceDays}).";
        }

        return $lines;
    }
}
