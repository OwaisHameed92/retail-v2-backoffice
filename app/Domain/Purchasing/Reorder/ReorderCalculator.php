<?php

namespace App\Domain\Purchasing\Reorder;

use App\Domain\Purchasing\Support\ReorderSuggestion;
use App\Domain\Shared\Support\Money;

/**
 * How many cases of one product a shop should order now (module 6.4). Pure: no database. Exact decimals.
 *
 *     lead      = supplier lead time (days until this order arrives)
 *     horizon   = lead + review days (this order has to last until the next one arrives)
 *     forecast  = DemandForecast over the horizon (weekday pattern × seasonal factor)
 *     position  = on hand + open orders + transfers on the way
 *     spare     = the shop's minimum level, else `safety_days` × daily rate
 *     need      = forecast + spare − position, capped at max level − position, at least the reorder quantity
 *     cases     = need ÷ case size, rounded UP to whole cases
 *
 * Caps round DOWN instead (never above the maximum, never more than can sell before its date), but still order one
 * case when the shop would run out before the next delivery. Short life: new stock must sell within its shelf life
 * after it arrives: cap = forecast(delivery day … + shelf life) − what is left of today's stock by then.
 * No sales history: the 5.2 min/max rule on the stock position (`levels`).
 */
final class ReorderCalculator
{
    private const S = 6;

    public static function calculate(ReorderInput $in): ReorderResult
    {
        $d = $in->demand;
        $caseQty = max(1, $in->caseQty);
        $horizon = max(1, $in->leadDays + $in->reviewDays);
        $factors = $in->factors();
        $position = bcadd(bcadd($in->onHand, $in->onOrder, self::S), $in->inTransit, self::S);
        $forecast = $d->forecast($in->today, $horizon, $factors);
        $leadDemand = $d->forecast($in->today, max(0, $in->leadDays), $factors);
        $hasRate = bccomp($d->rate, '0', self::S) > 0;
        $cover = $hasRate ? bcdiv(self::positive($in->onHand), $d->rate, self::S) : null;
        $flags = self::stockFlags($in, $position, $leadDemand, $hasRate, $cover, $horizon);
        $reasons = [];

        if (! $hasRate) {
            return self::fromLevels($in, $position, $caseQty, $flags);
        }

        $reasons[] = self::salesReason($d);
        $reasons[] = 'On hand '.self::qty($in->onHand).': about '.self::qty((string) $cover, 1).' days of cover.';

        if (bccomp(bcadd($in->onOrder, $in->inTransit, self::S), '0', self::S) > 0) {
            $reasons[] = 'Already coming: '.self::qty($in->onOrder).' on open orders and '.self::qty($in->inTransit).' on transfers.';
        }

        $reasons[] = "This order arrives in {$in->leadDays} ".self::days($in->leadDays)." and has to last {$horizon} days, until the next one arrives.";
        $reasons[] = 'Forecast for those '.$horizon.' days: '.self::qty($forecast, 1).self::eventsText($in).'.';

        $minimum = $in->minLevel !== null && bccomp($in->minLevel, '0', self::S) > 0;
        $safety = $minimum ? (string) $in->minLevel : bcmul($d->rate, (string) $in->safetyDays, self::S);
        $reasons[] = 'Keeps '.self::qty($safety, 1).' spare ('.($minimum ? 'the shop\'s minimum level' : "{$in->safetyDays} days of sales").').';
        $need = bcsub(bcadd($forecast, $safety, self::S), $position, self::S);
        [$capped, $roundDown] = [false, false];
        $reasons[] = 'Needs '.self::qty($forecast, 1).' + '.self::qty($safety, 1).' spare − '.self::qty($position, 1).' in stock and on the way = '.self::qty($need, 1).'.';

        if ($in->maxLevel !== null && bccomp($in->maxLevel, '0', self::S) > 0) {
            $room = bcsub($in->maxLevel, $position, self::S);

            if (bccomp($need, $room, self::S) > 0) {
                $need = $room;
                [$capped, $roundDown] = [true, true];
                $flags[] = 'capped';
                $reasons[] = 'Capped at the maximum level of '.self::qty($in->maxLevel).'.';
            }
        }

        if (bccomp($need, '0', self::S) <= 0) {
            $reasons[] = 'Enough stock: nothing to order this time.';

            return new ReorderResult(0, '0.0000', self::q($d->rate), self::cover($cover), self::q($forecast), self::q($position), self::q($safety), 'forecast', $flags, $reasons);
        }

        if (! $capped && $in->reorderQty !== null && bccomp($in->reorderQty, $need, self::S) > 0) {
            $need = $in->reorderQty;
            $reasons[] = 'Raised to the reorder quantity of '.self::qty($in->reorderQty).'.';
        }

        if ($in->shelfLifeDays !== null && $in->shelfLifeDays > 0) {
            $left = self::positive(bcsub($position, $leadDemand, self::S));
            $sellable = self::positive(bcsub($d->forecast($in->today->addDays($in->leadDays), $in->shelfLifeDays, $factors), $left, self::S));

            if (bccomp($need, $sellable, self::S) > 0) {
                $need = $sellable;
                $roundDown = true;
                $flags[] = 'shortLife';
                $reasons[] = "Short life (about {$in->shelfLifeDays} days): capped at the ".self::qty($sellable, 1).' it should sell before the date.';
            }
        }

        $cases = self::cases($need, $caseQty, $roundDown);

        if ($cases === 0 && $roundDown && bccomp($position, $leadDemand, self::S) < 0) {
            $cases = 1;
            $flags[] = 'wasteRisk';
            $reasons[] = 'One case, as the shop runs out before the delivery; some may go out of date.';
        }

        $units = (string) ($cases * $caseQty);

        if ($cases > 0 && ! in_array('wasteRisk', $flags, true)) {
            $reasons[] = 'Order '.self::caseText($cases, $caseQty).($roundDown ? ', rounded down to whole cases.' : ', rounded up to whole cases.');
        }

        return new ReorderResult($cases, self::q($units), self::q($d->rate), self::cover($cover), self::q($forecast), self::q($position), self::q($safety), 'forecast', $flags, $reasons);
    }

    /**
     * @param  list<string>  $flags
     */
    private static function fromLevels(ReorderInput $in, string $position, int $caseQty, array $flags): ReorderResult
    {
        $flags[] = 'noHistory';
        $cases = ReorderSuggestion::cases(self::q($position), $in->minLevel, $in->maxLevel, $in->reorderQty, $caseQty);
        $method = $in->minLevel !== null ? 'levels' : 'none';
        $reasons = ['No sales in the last weeks, so there is no forecast.', 'On hand '.self::qty($in->onHand).'; with what is coming, '.self::qty($position).'.'];
        $reasons[] = match (true) {
            $in->minLevel === null => 'No minimum level is set, so nothing is suggested. Set stock levels on the product to get one.',
            $cases > 0 => 'At or below the minimum of '.self::qty($in->minLevel).': '.self::caseText($cases, $caseQty).' to refill.',
            default => 'Above the minimum of '.self::qty($in->minLevel).': nothing to order.',
        };

        return new ReorderResult($cases, self::q((string) ($cases * $caseQty)), '0.0000', null, '0.0000', self::q($position), self::q($in->minLevel ?? '0'), $method, $flags, $reasons);
    }

    /**
     * @return list<string>
     */
    private static function stockFlags(ReorderInput $in, string $position, string $leadDemand, bool $hasRate, ?string $cover, int $horizon): array
    {
        $d = $in->demand;
        $flags = [];
        $weekly = bcmul($d->rate, '7', self::S);

        if (bccomp($in->onHand, '0', self::S) < 0) {
            $flags[] = 'negativeStock';
        } elseif (bccomp($in->onHand, '0', self::S) === 0) {
            $flags[] = 'outOfStock';
        }

        if ($hasRate && bccomp($position, $leadDemand, self::S) < 0) {
            $flags[] = 'runsOut';
        }

        if ($in->events !== []) {
            $flags[] = 'seasonal';
        }

        if ($hasRate && bccomp($d->lastWeekUnits, '7', self::S) >= 0 && bccomp($d->lastWeekUnits, bcmul($weekly, '1.6', self::S), self::S) >= 0) {
            $flags[] = 'spike';
        }

        if ($hasRate && $d->weeks >= 3 && bccomp($weekly, '7', self::S) >= 0 && bccomp(bcmul($d->lastWeekUnits, '2', self::S), $weekly, self::S) <= 0) {
            $flags[] = 'slowing';
        }

        if ($cover !== null && bccomp($cover, (string) max(28, 4 * $horizon), self::S) > 0) {
            $flags[] = 'overstock';
        }

        return $flags;
    }

    private static function salesReason(DemandForecast $d): string
    {
        $text = 'Sells about '.self::qty($d->rate, 1).' a day (weighted over the last '.$d->weeks.' '.($d->weeks === 1 ? 'week' : 'weeks').', latest weighs most)';
        $busiest = $d->busiestDay();
        $quiet = $d->quietDays();

        $text .= $busiest !== null ? "; busiest on {$busiest}" : '';
        $text .= $quiet !== [] ? '; none sold on '.implode(' or ', $quiet) : '';

        return $text.'.';
    }

    private static function eventsText(ReorderInput $in): string
    {
        $parts = array_map(fn (array $e) => $e['name'].' ×'.self::qty($e['factor'], 2).($e['basis'] === 'lastYear' ? ' from last year\'s sales' : ' (the till\'s uplift for the department)'), $in->events);

        return $parts === [] ? '' : ', including '.implode('; ', $parts);
    }

    private static function cases(string $need, int $caseQty, bool $roundDown): int
    {
        $whole = (int) bcdiv($need, (string) $caseQty, 0);

        return $roundDown || bccomp(bcmul((string) $whole, (string) $caseQty, self::S), $need, self::S) >= 0 ? $whole : $whole + 1;
    }

    private static function caseText(int $cases, int $caseQty): string
    {
        return $cases.' '.($cases === 1 ? 'case' : 'cases').' of '.$caseQty.' = '.($cases * $caseQty).' units';
    }

    private static function days(int $n): string
    {
        return $n === 1 ? 'day' : 'days';
    }

    /** A quantity for people: rounded, trailing zeros dropped ("12", "3.5"). */
    public static function qty(string $value, int $dp = 2): string
    {
        $rounded = Money::round(bcadd($value, '0', 8), $dp);

        return str_contains($rounded, '.') ? rtrim(rtrim($rounded, '0'), '.') : $rounded;
    }

    private static function q(string $value): string
    {
        return Money::round(bcadd($value, '0', 8), 4);
    }

    private static function cover(?string $cover): ?string
    {
        return $cover === null ? null : Money::round(bcadd($cover, '0', 8), 1);
    }

    private static function positive(string $value): string
    {
        return bccomp($value, '0', self::S) > 0 ? $value : '0';
    }
}
