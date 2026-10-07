<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\CompanyPricing;
use App\Domain\Billing\Support\InvoiceMaths;
use App\Domain\Demo\Support\DemoBusinesses;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Support\EmailControl;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonInterface;

/**
 * The "Change plan" preview in plain words (admin Billing tab): what the change does to features, the setup fee, the
 * recurring fee and how it is collected, the licences, the invoices it creates and the email. Built from a
 * PlanChangePlan; writes nothing.
 */
final class PlanChangePreview
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(PlanChangePlan $plan): array
    {
        $per = $plan->cycle->value === 'yearly' ? 'yearly' : 'monthly';

        return [
            'from' => $plan->from !== null ? self::plan($plan->from) : null,
            'to' => self::plan($plan->to),
            'blocked' => match (true) {
                $plan->company->isCancelled() => "{$plan->company->name} is cancelled. Its plan cannot be changed.",
                $plan->samePlan => "{$plan->company->name} is already on {$plan->to->name}.",
                default => null,
            },
            'features' => [
                'gained' => self::labels($plan->gained),
                'lost' => self::labels($plan->lost),
                'customShops' => array_map(fn (array $shop) => ['name' => $shop['name'], 'features' => self::labels($shop['features'])], $plan->customShops),
                'lines' => PlanChangeWords::features($plan),
            ],
            'setupFee' => [
                'applies' => $plan->setupKind !== null,
                'kind' => $plan->setupKind?->value,
                'suggested' => $plan->suggestedSetupFee,
                'amount' => $plan->setupFee,
                'gross' => $plan->setupFee !== null ? BillingFormat::money(self::gross($plan->setupFee, $plan->vatRate)) : null,
                'tills' => $plan->tills,
                'covered' => $plan->coveredTills,
                'uncovered' => $plan->uncoveredTills,
                'perTill' => $plan->perTill,
                'vatRate' => Money::isZero($plan->vatRate) ? null : BillingFormat::percent($plan->vatRate),
                'taxName' => app(Country::class)->taxName(),
                'lines' => PlanChangeWords::setupFee($plan),
            ],
            'recurring' => ['title' => ucfirst($per).' fee', 'lines' => PlanChangeWords::recurring($plan)],
            'licences' => ['lines' => PlanChangeWords::licences($plan)],
            'invoices' => self::invoices($plan),
            'notes' => self::notes($plan),
            'email' => self::email($plan),
        ];
    }

    /**
     * The plans a business can be moved to (active ones), for the "Change plan" dialog; `current` marks its plan now.
     *
     * @return array{currentPlanId: string|null, plans: list<array<string, mixed>>}
     */
    public static function options(Company $company): array
    {
        $current = CompanyPricing::for($company, app(BillingAccounts::class)->for($company))->plan;
        $plans = Plan::query()->where('is_active', true)->ordered()->get()->map(fn (Plan $plan) => [
            ...self::plan($plan),
            'setupFee' => Money::isZero($plan->setup_fee) ? null : BillingFormat::money($plan->setup_fee).($plan->setupFeePerTill() ? ' per till' : ''),
            'monthly' => Money::isZero($plan->price_monthly) ? null : BillingFormat::money($plan->price_monthly).' per '.$plan->pricing_mode->unit().' per month',
            'yearly' => Money::isZero($plan->price_yearly) ? null : BillingFormat::money($plan->price_yearly).' per '.$plan->pricing_mode->unit().' per year',
            'features' => self::labels(Feature::normalise($plan->features)),
            'current' => $plan->id === $current?->id,
        ])->values()->all();

        return ['currentPlanId' => $current?->id, 'plans' => $plans];
    }

    /**
     * @return array{id: string, name: string, typeLabel: string}
     */
    private static function plan(Plan $plan): array
    {
        return ['id' => $plan->id, 'name' => $plan->name, 'typeLabel' => $plan->billingType()->label()];
    }

    /**
     * @param  list<Feature>  $features
     * @return list<string>
     */
    private static function labels(array $features): array
    {
        return array_map(fn (Feature $feature) => $feature->label(), $features);
    }

    public static function gross(string $net, string $vatRate): string
    {
        return Money::add($net, InvoiceMaths::vatOn($net, $vatRate));
    }

    public static function day(?CarbonInterface $date): string
    {
        return $date === null ? '' : $date->format('j M Y');
    }

    /**
     * The invoices the change creates.
     *
     * @return list<array{title: string, amount: string, when: string}>
     */
    private static function invoices(PlanChangePlan $plan): array
    {
        $invoices = [];

        if ($plan->chargesSetupFee()) {
            $invoices[] = [
                'title' => $plan->setupKind === InvoiceKind::TillSetupFee ? 'Setup fee (tills not covered yet)' : 'Setup fee',
                'amount' => BillingFormat::money(self::gross((string) $plan->setupFee, $plan->vatRate)),
                'when' => 'Issued and emailed now. Paid by hand ('.PlanChangeWords::byHand($plan).').',
            ];
        }

        if ($plan->firstPeriodNow && $plan->firstPeriodGross !== null && $plan->periodEnd !== null) {
            $invoices[] = [
                'title' => 'First '.($plan->cycle->value === 'yearly' ? 'yearly' : 'monthly').' invoice, '.BillingDates::range($plan->today, $plan->periodEnd),
                'amount' => BillingFormat::money($plan->firstPeriodGross),
                'when' => match (true) {
                    $plan->manual => 'Issued and emailed now, due '.self::day($plan->firstPeriodDue).'. Paid by hand.',
                    $plan->mandateUsable => 'Raised now and collected by Direct Debit on the first day the mandate allows (emailed then).',
                    default => 'Raised now, due '.self::day($plan->firstPeriodDue).'. Collected by Direct Debit once it is set up (emailed then).',
                },
            ];
        }

        return $invoices;
    }

    /**
     * @return list<string>
     */
    private static function notes(PlanChangePlan $plan): array
    {
        $notes = [];

        foreach ($plan->openSetupInvoices as $invoice) {
            $notes[] = self::invoiceLabel($invoice).' (setup fee) is still unpaid: it stays owed. Void it on the invoice to waive it.';
        }

        if (! $plan->newRecurs) {
            foreach ($plan->openPeriodInvoices as $invoice) {
                $notes[] = self::invoiceLabel($invoice).' for '.BillingDates::range($invoice->period_start, $invoice->period_end).' stays owed. Void it if the business should not pay it.';
            }
        }

        if ($plan->company->status === CompanyStatus::Suspended) {
            $notes[] = 'The business is suspended. Changing the plan does not lift the suspension.';
        }

        if (DemoBusinesses::isDemo($plan->company)) {
            $notes[] = 'Demo business: nothing is sent to GoCardless and no email goes out.';
        }

        return $notes;
    }

    private static function invoiceLabel(Invoice $invoice): string
    {
        return ($invoice->number ?? 'A draft invoice').' ('.BillingFormat::money($invoice->balance).')';
    }

    private static function email(PlanChangePlan $plan): string
    {
        $category = EmailCategory::Reminders;

        return EmailControl::sendsAutomatically($category)
            ? "The owners get “Your plan has changed” by email now ({$category->label()})."
            : "The owners’ “Your plan has changed” email is held until you send it ({$category->label()} are not sent automatically: Business → Emails).";
    }

    /**
     * Licence names for sentences: "Till 2 (Leeds), Till 3 (Leeds)".
     *
     * @param  list<Licence>  $licences
     */
    public static function tillNames(array $licences): string
    {
        return implode(', ', array_map(function (Licence $licence) {
            $licence->loadMissing(['branch', 'register']);

            return ($licence->register->name ?? 'Till').' ('.($licence->branch->name ?? 'Branch').')';
        }, $licences));
    }
}
