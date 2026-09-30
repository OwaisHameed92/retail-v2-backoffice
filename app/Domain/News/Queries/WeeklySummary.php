<?php

namespace App\Domain\News\Queries;

use App\Domain\News\Support\NewsWeek;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The weekly news summary (module 5.8), per shop and per title, from the shops' delivery lines of the week (by
 * delivery date, Monday–Sunday): copies in, sold, returned and left over; cost (`lineCost`), return credit
 * (`returnValue`), net cost; sales = copies sold × the title's cover price (the till's lines carry no sell price);
 * margin = sales − net cost. Vouchers are those redeemed in the week (London time). Plus the last 8 weeks' sales and
 * margin for the trend. A shop filter (always set for a one-shop user) narrows everything to one shop.
 */
final class WeeklySummary
{
    /** @return array<string, mixed> */
    public static function for(NewsWeek $week, ?string $shop): array
    {
        $lines = self::lines($week->start, $week->end, $shop);
        $vouchers = self::vouchers($week, $shop);
        $names = Branch::query()->withTrashed()->pluck('name', 'id');
        $shops = [];

        foreach ($lines->groupBy('branch_id') as $branchId => $group) {
            $shops[] = ['shopId' => (string) $branchId, 'shop' => (string) ($names[$branchId] ?? 'Unknown shop'), ...self::figures($group->all(), $vouchers[$branchId] ?? null)];
        }

        foreach ($vouchers as $branchId => $v) {
            if (! $lines->contains('branch_id', $branchId)) {
                $shops[] = ['shopId' => (string) $branchId, 'shop' => (string) ($names[$branchId] ?? 'Unknown shop'), ...self::figures([], $v)];
            }
        }

        usort($shops, fn ($a, $b) => strcmp($a['shop'], $b['shop']));
        $titles = [];

        foreach ($lines->groupBy('title_key') as $group) {
            $rows = array_values($group->all());
            $titles[] = [
                'title' => (string) ($rows[0]->name ?: $rows[0]->title_name ?: 'Unknown title'),
                'publisher' => $rows[0]->publisher ?: null,
                ...self::figures($rows, null),
            ];
        }

        usort($titles, fn (array $a, array $b): int => Money::compare($b['sales'], $a['sales']) ?: strcmp($a['title'], $b['title']));

        return [
            'week' => $week->toArray(),
            'byShop' => $shops,
            'total' => self::figures($lines->all(), self::sumVouchers($vouchers)),
            'titles' => $titles,
            'trend' => self::trend($week, $shop),
        ];
    }

    /** @return Collection<int, stdClass> */
    private static function lines(string $from, string $to, ?string $shop)
    {
        return DB::table('news_delivery_lines as l')
            ->join('news_deliveries as d', 'd.id', '=', 'l.delivery_id')
            ->leftJoin('news_titles as t', fn ($j) => $j->on('t.id', '=', 'l.title_id')->on('t.company_id', '=', 'l.company_id'))
            ->where('l.company_id', app(CurrentCompany::class)->id())->where('d.company_id', app(CurrentCompany::class)->id())
            ->whereNull('l.deleted_at')->whereNull('d.deleted_at')
            ->whereBetween('d.delivery_date', [$from, $to])
            ->when($shop !== null, fn ($q) => $q->where('d.branch_id', $shop))
            ->groupBy('d.branch_id', 'l.title_id', 'l.title_name', 't.name', 't.publisher', 't.cover_price')
            ->selectRaw("d.branch_id, coalesce(l.title_id, l.title_name, '') as title_key, l.title_name, t.name, t.publisher, t.cover_price,
                sum(coalesce(l.qty_in, 0)) as qty_in, sum(coalesce(l.qty_sold, 0)) as qty_sold, sum(coalesce(l.qty_returned, 0)) as qty_returned,
                sum(coalesce(l.line_cost, 0)) as cost, sum(coalesce(l.return_value, 0)) as credit")
            ->get();
    }

    /**
     * @param  list<stdClass>  $lines  grouped rows of `lines()`
     * @param  array{count: int, value: string}|null  $vouchers
     * @return array<string, mixed>
     */
    private static function figures(array $lines, ?array $vouchers): array
    {
        $in = $sold = $returned = 0;
        $cost = $credit = $sales = '0.00';

        foreach ($lines as $line) {
            $in += (int) $line->qty_in;
            $sold += (int) $line->qty_sold;
            $returned += (int) $line->qty_returned;
            $cost = Money::add($cost, Money::normalise($line->cost ?? 0, 4), 4);
            $credit = Money::add($credit, Money::normalise($line->credit ?? 0));
            $sales = Money::add($sales, Money::mul((int) $line->qty_sold, $line->cover_price ?? 0));
        }

        $cost = Money::normalise($cost);
        $net = Money::sub($cost, $credit);
        $margin = Money::sub($sales, $net);

        return [
            'qtyIn' => $in, 'qtySold' => $sold, 'qtyReturned' => $returned, 'qtyUnaccounted' => max(0, $in - $sold - $returned),
            'sellThrough' => $in > 0 ? round(100 * $sold / $in, 1) : null,
            'cost' => $cost, 'credit' => $credit, 'netCost' => $net, 'sales' => $sales, 'margin' => $margin,
            'marginPercent' => Money::isZero($sales) ? null : round(100 * (float) $margin / (float) $sales, 1),
            ...($vouchers === null ? [] : ['vouchers' => $vouchers['count'], 'voucherValue' => $vouchers['value']]),
        ];
    }

    /** @return array<string, array{count: int, value: string}> */
    private static function vouchers(NewsWeek $week, ?string $shop): array
    {
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $week->start, 'Europe/London')->utc();
        $to = $from->addWeek();

        return DB::table('news_voucher_redemptions')->where('company_id', app(CurrentCompany::class)->id())->whereNull('deleted_at')
            ->where('redeemed_at', '>=', $from->format('Y-m-d H:i:s'))->where('redeemed_at', '<', $to->format('Y-m-d H:i:s'))
            ->when($shop !== null, fn ($q) => $q->where('branch_id', $shop))
            ->groupBy('branch_id')->selectRaw('branch_id, count(*) as n, sum(coalesce(amount, 0)) as value')->get()
            ->mapWithKeys(fn ($r) => [(string) $r->branch_id => ['count' => (int) $r->n, 'value' => Money::normalise($r->value ?? 0)]])->all();
    }

    /**
     * @param  array<string, array{count: int, value: string}>  $vouchers
     * @return array{count: int, value: string}
     */
    private static function sumVouchers(array $vouchers): array
    {
        return ['count' => array_sum(array_column($vouchers, 'count')), 'value' => Money::sum(array_column($vouchers, 'value'))];
    }

    /** @return list<array{week: string, sales: string, margin: string}> */
    private static function trend(NewsWeek $week, ?string $shop): array
    {
        $trend = [];

        for ($i = 7; $i >= 0; $i--) {
            $w = $week->shift(-$i);
            $f = self::figures(self::lines($w->start, $w->end, $shop)->all(), null);
            $trend[] = ['week' => $w->start, 'sales' => $f['sales'], 'margin' => $f['margin']];
        }

        return $trend;
    }
}
