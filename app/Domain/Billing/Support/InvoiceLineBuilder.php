<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\LicenceTerms;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One invoice line per live licence of an active till: "Standard plan · Till 1, Leeds · 1 Oct – 31 Oct 2026",
 * quantity 1 at the plan's price for the cycle. With proration, a till whose paid or trial time already runs
 * into the period is charged only for the days after it (quantity = days / period days, 4 dp); a till covered
 * for the whole period gets no line.
 */
final class InvoiceLineBuilder
{
    /**
     * @param  Collection<int, Licence>  $licences  Renewable licences (branch, register, plan loaded).
     * @return list<array{licence_id: string, register_id: string, plan_id: string, description: string, quantity: string, unit_price: string, net: string, vat: string, gross: string, period_start: string, period_end: string, position: int}>
     */
    public static function build(Collection $licences, CarbonImmutable $start, CarbonImmutable $end, BillingCycle $cycle, string $vatRate, bool $prorate): array
    {
        $periodDays = BillingDates::daysInclusive($start, $end);
        $lines = [];

        $sorted = $licences->sortBy(fn (Licence $licence) => [
            mb_strtolower($licence->branch->name ?? ''),
            $licence->register->code ?? '',
        ])->values();

        foreach ($sorted as $licence) {
            $plan = $licence->plan;
            $unitPrice = $plan === null ? '0.00' : ($cycle === BillingCycle::Yearly ? $plan->price_per_till_yearly : $plan->price_per_till_monthly);
            $quantity = '1.0000';
            $lineStart = $start;
            $note = '';

            if ($prorate) {
                $currentEnd = LicenceTerms::endsAt($licence);
                $coveredUntil = $currentEnd !== null ? BillingDates::londonDate($currentEnd) : null;

                if ($coveredUntil !== null && $coveredUntil->greaterThanOrEqualTo($start)) {
                    if ($coveredUntil->greaterThanOrEqualTo($end)) {
                        continue;
                    }

                    $lineStart = $coveredUntil->addDay();
                    $days = BillingDates::daysInclusive($lineStart, $end);
                    $quantity = InvoiceMaths::ratio($days, $periodDays);
                    $note = " ({$days} of {$periodDays} days)";
                }
            }

            $amounts = InvoiceMaths::line($quantity, $unitPrice, $vatRate);

            $lines[] = [
                'licence_id' => $licence->id,
                'register_id' => $licence->register_id,
                'plan_id' => $licence->plan_id,
                'description' => self::describe($licence, BillingDates::range($lineStart, $end)).$note,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'net' => $amounts['net'],
                'vat' => $amounts['vat'],
                'gross' => $amounts['gross'],
                'period_start' => $lineStart->format('Y-m-d'),
                'period_end' => $end->format('Y-m-d'),
                'position' => count($lines) + 1,
            ];
        }

        return $lines;
    }

    /** "Standard plan · Till 1, Leeds · 1 Oct – 31 Oct 2026" */
    public static function describe(Licence $licence, string $range): string
    {
        $plan = trim($licence->plan->name ?? 'Licence');
        $planLabel = str_ends_with(mb_strtolower($plan), 'plan') ? $plan : $plan.' plan';
        $till = $licence->register->name ?? 'Till';
        $branch = $licence->branch->name ?? null;

        return $planLabel.' · '.$till.($branch !== null ? ', '.$branch : '').' · '.$range;
    }
}
