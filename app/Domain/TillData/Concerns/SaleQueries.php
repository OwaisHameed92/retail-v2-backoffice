<?php

namespace App\Domain\TillData\Concerns;

use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Enums\SaleStatus;
use App\Domain\TillData\Enums\SaleType;
use App\Domain\TillData\Queries\TillSum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-model helpers for sales (phase 3 screens and reports).
 *
 *     Sale::query()->trading()->forBranch($branch)->completedBetween($from, $to);
 *     Sale::totals(Sale::query()->trading()->forBranch($branch));   // exact strings from the database
 *
 * @mixin Model
 */
trait SaleQueries
{
    /** Sale types that are real trading (quotes and training sales are not). */
    public const TRADING_TYPES = [SaleType::Sale, SaleType::Refund, SaleType::Exchange, SaleType::Deposit];

    /**
     * @param  Builder<Model>  $query
     */
    public function scopeCompleted(Builder $query): void
    {
        $query->where($this->qualifyColumn('status'), SaleStatus::Completed->value);
    }

    /**
     * Completed sales of a trading type: what sales reports count.
     *
     * @param  Builder<Model>  $query
     */
    public function scopeTrading(Builder $query): void
    {
        $query->where($this->qualifyColumn('status'), SaleStatus::Completed->value)
            ->whereIn($this->qualifyColumn('type'), array_map(fn (SaleType $t) => $t->value, self::TRADING_TYPES));
    }

    /**
     * Completed in [from, to): both converted to UTC.
     *
     * @param  Builder<Model>  $query
     */
    public function scopeCompletedBetween(Builder $query, DateTimeInterface $from, DateTimeInterface $to): void
    {
        $query->where($this->qualifyColumn('completed_at'), '>=', self::utc($from))
            ->where($this->qualifyColumn('completed_at'), '<', self::utc($to));
    }

    /**
     * Count and money totals of the given sales query, summed exactly in the database.
     *
     * @param  Builder<Model>  $query
     * @return array{count: int, total: string, vat_total: string, net_total: string, discount_total: string, promo_total: string}
     */
    public static function totals(Builder $query): array
    {
        $sums = TillSum::many($query, ['total' => 2, 'vat_total' => 2, 'discount_total' => 2, 'promo_total' => 2]);

        return [
            'count' => $query->clone()->count(),
            'total' => $sums['total'],
            'vat_total' => $sums['vat_total'],
            'net_total' => Money::sub($sums['total'], $sums['vat_total']),
            'discount_total' => $sums['discount_total'],
            'promo_total' => $sums['promo_total'],
        ];
    }

    private static function utc(DateTimeInterface $at): string
    {
        return (new \DateTimeImmutable('@'.$at->getTimestamp()))->format('Y-m-d H:i:s');
    }
}
