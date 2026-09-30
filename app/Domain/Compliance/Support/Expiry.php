<?php

namespace App\Domain\Compliance\Support;

use App\Domain\Reporting\Support\TradingDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Expiry dates of training and licences (module 5.7). A date is a London day: `expired` once it is before today,
 * `expiring` from SOON days before (training 30 days, licences 60: a premises or tobacco licence takes weeks to
 * renew), `valid` before that, `none` when it never expires. The same rules filter lists in SQL.
 */
final class Expiry
{
    public const TRAINING_SOON_DAYS = 30;

    public const LICENCE_SOON_DAYS = 60;

    /** @return 'valid'|'expiring'|'expired'|'none' */
    public static function status(?CarbonImmutable $expiresOn, int $soonDays, ?CarbonImmutable $today = null): string
    {
        if ($expiresOn === null) {
            return 'none';
        }

        $day = $expiresOn->format('Y-m-d');
        $today ??= TradingDay::today();

        return match (true) {
            $day < $today->format('Y-m-d') => 'expired',
            $day <= $today->addDays($soonDays)->format('Y-m-d') => 'expiring',
            default => 'valid',
        };
    }

    /** Whole days from today to the expiry date (negative once expired), or null when it never expires. */
    public static function daysLeft(?CarbonImmutable $expiresOn, ?CarbonImmutable $today = null): ?int
    {
        if ($expiresOn === null) {
            return null;
        }

        $today ??= TradingDay::today();

        return (int) CarbonImmutable::parse($today->format('Y-m-d'), 'UTC')->diffInDays(CarbonImmutable::parse($expiresOn->format('Y-m-d'), 'UTC'), false);
    }

    /**
     * Narrow a query to one expiry status (`valid` includes dates that never expire).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function filter(Builder $query, ?string $status, int $soonDays, string $column = 'expires_on'): Builder
    {
        $today = TradingDay::today()->format('Y-m-d');
        $soon = TradingDay::today()->addDays($soonDays)->format('Y-m-d');
        $col = $query->getModel()->qualifyColumn($column);

        return match ($status) {
            'expired' => $query->whereNotNull($col)->where($col, '<', $today),
            'expiring' => $query->where($col, '>=', $today)->where($col, '<=', $soon),
            'valid' => $query->where(fn (Builder $q) => $q->whereNull($col)->orWhere($col, '>', $soon)),
            default => $query,
        };
    }

    /**
     * Sorting by expiry: "never expires" after every date (before them when newest first).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function nullsLast(Builder $query, ?string $sort, string $direction, string $column = 'expires_on'): Builder
    {
        return $query->when($sort === $column, fn (Builder $q) => $q->orderByRaw($q->getModel()->qualifyColumn($column).' is null '.($direction === 'desc' ? 'desc' : 'asc')));
    }
}
