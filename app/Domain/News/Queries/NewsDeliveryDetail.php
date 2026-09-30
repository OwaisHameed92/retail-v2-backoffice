<?php

namespace App\Domain\News\Queries;

use App\Domain\Purchasing\Support\PurchasingNames;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\NewsDelivery;
use App\Domain\TillData\Models\NewsDeliveryLine;
use App\Domain\TillData\Models\NewsTitle;

/**
 * One news delivery (module 5.8), read only: its lines by title with copies in, sold, returned and left over, cost,
 * return credit and sales at the title's cover price, and the totals.
 */
final class NewsDeliveryDetail
{
    /** @return array<string, mixed> */
    public static function for(NewsDelivery $delivery): array
    {
        $names = PurchasingNames::for([$delivery]);
        $lines = NewsDeliveryLine::query()->where('delivery_id', $delivery->id)->orderBy('title_name')->get();
        $prices = NewsTitle::query()->withTrashed()->whereKey($lines->pluck('title_id')->filter()->unique()->values()->all())->pluck('cover_price', 'id');

        $rows = $lines->map(function (NewsDeliveryLine $l) use ($prices) {
            $price = $prices[$l->title_id] ?? null;
            $in = (int) $l->qty_in;
            $sold = (int) $l->qty_sold;
            $returned = (int) $l->qty_returned;

            return [
                'id' => $l->id,
                'title' => $l->title_name ?: 'Unknown title',
                'qtyIn' => $in,
                'qtySold' => $sold,
                'qtyReturned' => $returned,
                'qtyUnaccounted' => max(0, $in - $sold - $returned),
                'unitCost' => $l->unit_cost,
                'cost' => Money::normalise($l->line_cost ?? 0),
                'credit' => Money::normalise($l->return_value ?? 0),
                'sales' => $price === null ? null : Money::mul($sold, $price),
            ];
        })->values()->all();

        $cost = Money::sum(array_column($rows, 'cost'));
        $credit = Money::sum(array_column($rows, 'credit'));
        $sales = Money::sum(array_filter(array_column($rows, 'sales'), fn ($v) => $v !== null));

        return [
            'delivery' => [
                'id' => $delivery->id,
                'date' => $delivery->delivery_date->format('Y-m-d'),
                'status' => $delivery->status?->value,
                'shop' => $names->shop($delivery->branch_id),
                'supplier' => $names->supplier($delivery->supplier_id, $delivery->supplier_name),
                'creditPostedAt' => $delivery->credit_posted_at?->toIso8601ZuluString(),
                'notes' => $delivery->notes ?: null,
            ],
            'lines' => $rows,
            'totals' => [
                'qtyIn' => array_sum(array_column($rows, 'qtyIn')),
                'qtySold' => array_sum(array_column($rows, 'qtySold')),
                'qtyReturned' => array_sum(array_column($rows, 'qtyReturned')),
                'cost' => $cost,
                'credit' => $credit,
                'netCost' => Money::sub($cost, $credit),
                'sales' => $sales,
                'margin' => Money::sub($sales, Money::sub($cost, $credit)),
            ],
        ];
    }
}
