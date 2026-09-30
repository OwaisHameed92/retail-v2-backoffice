<?php

namespace App\Domain\News\Queries\Lists;

use App\Domain\News\Support\NewsWeek;
use App\Domain\Purchasing\Data\PurchasingFilters;
use App\Domain\Purchasing\Queries\Lists\DocumentRows;
use App\Domain\Purchasing\Support\PurchasingNames;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\NewsVoucherRedemption;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * News vouchers taken at the tills (module 5.8): subscription and home-delivery vouchers (HHDV) redeemed against a
 * title, read only. Unclaimed = not yet claimed back from the publisher or its clearing house (`claimedAt` blank).
 *
 * @extends DocumentRows<NewsVoucherRedemption>
 */
final class NewsVoucherRows extends DocumentRows
{
    protected function base(): Builder
    {
        return NewsVoucherRedemption::query();
    }

    public function statuses(): array
    {
        return ['unclaimed', 'claimed'];
    }

    protected function status(Builder $query, string $status): void
    {
        $status === 'claimed' ? $query->whereNotNull('claimed_at') : $query->whereNull('claimed_at');
    }

    /** Vouchers carry no supplier: the supplier filter does not apply. */
    public function filtered(PurchasingFilters $filters): Builder
    {
        return parent::filtered(new PurchasingFilters($filters->shop, null, $filters->status, null, $filters->pinned));
    }

    protected function sortable(): array
    {
        return ['redeemed_at', 'amount', 'voucher_code', 'claimed_at'];
    }

    protected function defaultSort(): array
    {
        return ['redeemed_at', 'desc'];
    }

    protected function search(Builder $query, string $like): void
    {
        $query->where('voucher_code', 'like', $like)->orWhere('title_name', 'like', $like);
    }

    protected function rows(array $rows, PurchasingNames $names): array
    {
        return array_map(fn (NewsVoucherRedemption $v) => [
            'id' => $v->id,
            'reference' => $v->voucher_code ?: 'No code',
            'status' => $v->claimed_at !== null ? 'claimed' : 'unclaimed',
            'shop' => $names->shop($v->branch_id),
            'supplier' => $v->title_name ?: 'Unknown title',
            'title' => $v->title_name ?: null,
            'redeemedAt' => $v->redeemed_at->toIso8601ZuluString(),
            'claimedAt' => $v->claimed_at?->toIso8601ZuluString(),
            'gross' => $v->amount,
        ], $rows);
    }

    public function stats(Builder $query): array
    {
        $week = NewsWeek::current();
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $week->start, 'Europe/London')->utc();
        $thisWeek = (clone $query)->where('redeemed_at', '>=', $from->format('Y-m-d H:i:s'));
        $unclaimed = (clone $query)->whereNull('claimed_at');
        $claimed = (clone $query)->where('claimed_at', '>=', CarbonImmutable::now('UTC')->subDays(30)->format('Y-m-d H:i:s'));

        return [
            self::stat('Taken this week', (clone $thisWeek)->count(), 'count', 'primary', 'Vouchers redeemed at the tills'),
            self::stat('Value this week', Money::normalise((clone $thisWeek)->sum('amount') ?: 0), 'money', 'neutral', 'Owed back to you'),
            self::stat('Unclaimed', Money::normalise((clone $unclaimed)->sum('amount') ?: 0), 'money', 'warning', (clone $unclaimed)->count().' vouchers to claim'),
            self::stat('Claimed', Money::normalise((clone $claimed)->sum('amount') ?: 0), 'money', 'success', 'Last 30 days'),
        ];
    }
}
