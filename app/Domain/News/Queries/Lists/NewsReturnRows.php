<?php

namespace App\Domain\News\Queries\Lists;

use App\Domain\Purchasing\Queries\Lists\DocumentRows;
use App\Domain\Purchasing\Support\PurchasingNames;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Enums\NewsDeliveryStatus;
use App\Domain\TillData\Models\NewsDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Returns to the wholesaler and the credit for them (module 5.8): the shops' news deliveries with copies sent back,
 * read only. Credited = the shop posted the credit (`creditPostedAt`) or settled the delivery; else awaiting credit.
 *
 * @extends DocumentRows<NewsDelivery>
 */
final class NewsReturnRows extends DocumentRows
{
    /** The shop of the last `scoped()` list: the return rate is over every delivery of that shop. */
    private ?string $shop = null;

    public function scoped(?string $shop): Builder
    {
        $this->shop = $shop;

        return parent::scoped($shop);
    }

    protected function base(): Builder
    {
        return NewsDelivery::query()->whereIn('news_deliveries.id', fn ($q) => $q->select('delivery_id')->from('news_delivery_lines')
            ->whereNull('deleted_at')->where('qty_returned', '>', 0));
    }

    public function statuses(): array
    {
        return ['awaitingCredit', 'credited'];
    }

    protected function status(Builder $query, string $status): void
    {
        $credited = fn (Builder $q) => $q->whereNotNull('credit_posted_at')->orWhere('status', NewsDeliveryStatus::Settled->value);

        $status === 'credited'
            ? $query->where($credited)
            : $query->whereNull('credit_posted_at')->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<>', NewsDeliveryStatus::Settled->value));
    }

    protected function sortable(): array
    {
        return ['delivery_date', 'credit_posted_at'];
    }

    protected function defaultSort(): array
    {
        return ['delivery_date', 'desc'];
    }

    protected function search(Builder $query, string $like): void
    {
        $query->where('supplier_name', 'like', $like)->orWhere('notes', 'like', $like);
        $this->supplierMatch($query, $like);
    }

    protected function rows(array $rows, PurchasingNames $names): array
    {
        $totals = NewsDeliveryRows::totals(array_map(fn (NewsDelivery $d) => $d->id, $rows));

        return array_map(function (NewsDelivery $d) use ($names, $totals) {
            $t = $totals->get($d->id);
            $credited = $d->credit_posted_at !== null || $d->status === NewsDeliveryStatus::Settled;

            return [
                'id' => $d->id,
                'reference' => $d->delivery_date->format('D j M Y'),
                'status' => $credited ? 'credited' : 'awaitingCredit',
                'shop' => $names->shop($d->branch_id),
                'supplier' => $names->supplier($d->supplier_id, $d->supplier_name),
                'date' => $d->delivery_date->format('Y-m-d'),
                'qtyIn' => $t->qty_in ?? 0,
                'qtyReturned' => $t->qty_returned ?? 0,
                'returnRate' => ($t->qty_in ?? 0) > 0 ? round(100 * $t->qty_returned / $t->qty_in, 1) : null,
                'gross' => $t->credit ?? '0.00',
                'creditPostedAt' => $d->credit_posted_at?->toIso8601ZuluString(),
            ];
        }, $rows);
    }

    public function stats(Builder $query): array
    {
        $since = CarbonImmutable::now('Europe/London')->subDays(29)->format('Y-m-d');
        $recent = NewsDeliveryRows::totals((clone $query)->where('delivery_date', '>=', $since));
        $all = NewsDeliveryRows::totals((new NewsDeliveryRows)->scoped($this->shop)->where('delivery_date', '>=', $since));
        $awaiting = clone $query;
        $this->status($awaiting, 'awaitingCredit');
        $in = (int) $all->sum('qty_in');

        return [
            self::stat('Returned', (string) $recent->sum('qty_returned'), 'count', 'primary', 'Copies, last 30 days'),
            self::stat('Return credit', Money::sum($recent->pluck('credit')), 'money', 'success', 'Last 30 days'),
            self::stat('Awaiting credit', Money::sum(NewsDeliveryRows::totals($awaiting)->pluck('credit')), 'money', 'warning', 'Returned, not yet credited'),
            self::stat('Return rate', $in > 0 ? (string) round(100 * (int) $recent->sum('qty_returned') / $in, 1) : '0', 'percent', 'neutral', 'Of all copies in, last 30 days'),
        ];
    }
}
