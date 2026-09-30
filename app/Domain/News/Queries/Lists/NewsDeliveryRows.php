<?php

namespace App\Domain\News\Queries\Lists;

use App\Domain\News\Support\NewsWeek;
use App\Domain\Purchasing\Queries\Lists\DocumentRows;
use App\Domain\Purchasing\Support\PurchasingNames;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Enums\NewsDeliveryStatus;
use App\Domain\TillData\Models\NewsDelivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * News deliveries booked in at the shops (module 5.8), read only (the till owns them), with their lines' copies in,
 * sold and returned, cost and return credit.
 *
 * @extends DocumentRows<NewsDelivery>
 */
final class NewsDeliveryRows extends DocumentRows
{
    protected function base(): Builder
    {
        return NewsDelivery::query();
    }

    public function statuses(): array
    {
        return array_map(fn (NewsDeliveryStatus $s) => $s->value, NewsDeliveryStatus::cases());
    }

    protected function sortable(): array
    {
        return ['delivery_date', 'supplier_name'];
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

    /**
     * Line totals per delivery.
     *
     * @param  list<string>|Builder<NewsDelivery>  $deliveries
     * @return Collection<string, object{delivery_id: string, lines: int, qty_in: int, qty_sold: int, qty_returned: int, cost: string, credit: string}>
     */
    public static function totals(array|Builder $deliveries): Collection
    {
        $ids = $deliveries instanceof Builder ? (clone $deliveries)->select('news_deliveries.id') : $deliveries;

        /** @var Collection<string, object{delivery_id: string, lines: int, qty_in: int, qty_sold: int, qty_returned: int, cost: string, credit: string}> */
        return DB::table('news_delivery_lines')->whereIn('delivery_id', $ids)->whereNull('deleted_at')->groupBy('delivery_id')
            ->selectRaw('delivery_id, count(*) as lines, sum(coalesce(qty_in, 0)) as qty_in, sum(coalesce(qty_sold, 0)) as qty_sold,
                sum(coalesce(qty_returned, 0)) as qty_returned, sum(coalesce(line_cost, 0)) as cost, sum(coalesce(return_value, 0)) as credit')
            ->get()->map(function (object $t) {
                return (object) [
                    'delivery_id' => (string) $t->delivery_id, 'lines' => (int) $t->lines, 'qty_in' => (int) $t->qty_in, 'qty_sold' => (int) $t->qty_sold,
                    'qty_returned' => (int) $t->qty_returned, 'cost' => Money::normalise($t->cost ?? 0), 'credit' => Money::normalise($t->credit ?? 0),
                ];
            })->keyBy('delivery_id');
    }

    protected function rows(array $rows, PurchasingNames $names): array
    {
        $totals = self::totals(array_map(fn (NewsDelivery $d) => $d->id, $rows));

        return array_map(function (NewsDelivery $d) use ($names, $totals) {
            $t = $totals->get($d->id);

            return [
                'id' => $d->id,
                'reference' => $d->delivery_date->format('D j M Y'),
                'status' => $d->status?->value,
                'shop' => $names->shop($d->branch_id),
                'supplier' => $names->supplier($d->supplier_id, $d->supplier_name),
                'date' => $d->delivery_date->format('Y-m-d'),
                'lines' => $t->lines ?? 0,
                'qtyIn' => $t->qty_in ?? 0,
                'qtySold' => $t->qty_sold ?? 0,
                'qtyReturned' => $t->qty_returned ?? 0,
                'cost' => $t->cost ?? '0.00',
                'credit' => $t->credit ?? '0.00',
                'gross' => Money::sub($t->cost ?? '0', $t->credit ?? '0'),
                'creditPostedAt' => $d->credit_posted_at?->toIso8601ZuluString(),
            ];
        }, $rows);
    }

    public function stats(Builder $query): array
    {
        $week = NewsWeek::current();
        $thisWeek = (clone $query)->whereBetween('delivery_date', [$week->start, $week->end]);
        $totals = self::totals($thisWeek);

        return [
            self::stat('Deliveries', (clone $thisWeek)->count(), 'count', 'primary', 'This week'),
            self::stat('Copies in', (string) $totals->sum('qty_in'), 'count', 'neutral', 'This week'),
            self::stat('Cost', Money::sum($totals->pluck('cost')), 'money', 'neutral', 'This week, before returns'),
            self::stat('Not settled', (clone $query)->where('status', '<>', NewsDeliveryStatus::Settled->value)->count(), 'count', 'warning', 'Returns or credit still open'),
        ];
    }
}
