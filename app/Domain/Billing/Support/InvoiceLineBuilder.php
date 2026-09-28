<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\LicenceTerms;
use App\Domain\Plans\Enums\PricingMode;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The lines of a period invoice (module 1.8, pricing modes 1.13):
 *
 * - per till: one line per live licence of an active till, "Standard plan · Till 1, Leeds · 1 Oct – 31 Oct 2026";
 * - per branch: one line per active branch with live tills, "Standard plan · Leeds (2 tills) · 1 Oct – 31 Oct
 *   2026" (paying it renews that branch's tills).
 *
 * Quantity 1 at the unit price for the cycle (CompanyPricing). With proration, a unit whose paid or trial time
 * already runs into the period is charged only for the days after it (quantity = days / period days, 4 dp; for a
 * branch, after its earliest-ending till); a unit covered for the whole period gets no line.
 *
 * @phpstan-type Line array{licence_id: string|null, register_id: string|null, branch_id: string|null, plan_id: string|null, description: string, quantity: string, unit_price: string, net: string, vat: string, gross: string, period_start: string, period_end: string, position: int}
 */
final class InvoiceLineBuilder
{
    /**
     * @param  Collection<int, Licence>  $licences  Renewable licences (branch, register, plan loaded).
     * @return list<Line>
     */
    public static function build(Collection $licences, CarbonImmutable $start, CarbonImmutable $end, BillingCycle $cycle, string $vatRate, bool $prorate, CompanyPricing $pricing): array
    {
        $units = $pricing->mode === PricingMode::PerBranch ? self::branchUnits($licences) : self::tillUnits($licences);
        $periodDays = BillingDates::daysInclusive($start, $end);
        $lines = [];

        foreach ($units as $unit) {
            $quantity = '1.0000';
            $lineStart = $start;
            $note = '';

            if ($prorate) {
                $ends = $unit['licences']->map(fn (Licence $licence) => LicenceTerms::endsAt($licence));
                $coveredUntil = $ends->contains(null) ? null : $ends->filter()->min();
                $coveredUntil = $coveredUntil !== null ? BillingDates::londonDate($coveredUntil) : null;

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

            /** @var Licence $first */
            $first = $unit['licences']->first();
            $unitPrice = $pricing->unitPrice($cycle, $first->plan);
            $amounts = InvoiceMaths::line($quantity, $unitPrice, $vatRate);
            $range = BillingDates::range($lineStart, $end);

            $lines[] = [
                'licence_id' => $unit['branch'] === null ? $first->id : null,
                'register_id' => $unit['branch'] === null ? $first->register_id : null,
                'branch_id' => $unit['branch']?->id,
                'plan_id' => $unit['branch'] === null ? $first->plan_id : ($pricing->plan->id ?? $first->plan_id),
                'description' => ($unit['branch'] === null ? self::describe($first, $range) : self::describeBranch($unit['branch'], $unit['licences']->count(), $pricing, $range)).$note,
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
        $till = $licence->register->name ?? 'Till';
        $branch = $licence->branch->name ?? null;

        return self::planLabel($licence->plan->name ?? null).' · '.$till.($branch !== null ? ', '.$branch : '').' · '.$range;
    }

    /** "Standard plan · Leeds (2 tills) · 1 Oct – 31 Oct 2026" */
    public static function describeBranch(Branch $branch, int $tills, CompanyPricing $pricing, string $range): string
    {
        return self::planLabel($pricing->plan->name ?? null).' · '.$branch->name.' ('.PricingMode::PerTill->units($tills).') · '.$range;
    }

    private static function planLabel(?string $name): string
    {
        $plan = trim($name ?? 'Licence');

        return str_ends_with(mb_strtolower($plan), 'plan') ? $plan : $plan.' plan';
    }

    /**
     * @param  Collection<int, Licence>  $licences
     * @return list<array{branch: null, licences: Collection<int, Licence>}>
     */
    private static function tillUnits(Collection $licences): array
    {
        return $licences->sortBy(fn (Licence $licence) => [mb_strtolower($licence->branch->name ?? ''), $licence->register->code ?? ''])
            ->values()
            ->map(fn (Licence $licence) => ['branch' => null, 'licences' => collect([$licence])])
            ->all();
    }

    /**
     * @param  Collection<int, Licence>  $licences
     * @return list<array{branch: Branch, licences: Collection<int, Licence>}>
     */
    private static function branchUnits(Collection $licences): array
    {
        return $licences->filter(fn (Licence $licence) => $licence->branch !== null)
            ->groupBy('branch_id')
            ->map(fn (Collection $group) => ['branch' => $group->first()->branch, 'licences' => $group->values()])
            ->sortBy(fn (array $unit) => mb_strtolower($unit['branch']->name))
            ->values()
            ->all();
    }
}
