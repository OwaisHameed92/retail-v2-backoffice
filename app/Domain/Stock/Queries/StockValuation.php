<?php

namespace App\Domain\Stock\Queries;

use App\Domain\Shared\Support\Money;
use App\Domain\Stock\Data\StockFilters;
use App\Domain\Stock\Support\FifoValuation;
use Illuminate\Support\Facades\DB;

/**
 * Stock valuation (module 5.1): every stock line with something on hand valued FIFO from the till's cost layers
 * (`fifo_stock_layers`, FifoValuation), next to the same stock at today's cost price. Totals for the scope, by shop,
 * by department, and the 25 lines worth most. Lines are streamed (`cursor`); only the scope's layers with something
 * left are held in memory, grouped by shop and product.
 */
final class StockValuation
{
    public const TOP = 25;

    public function __construct(private readonly StockLines $lines) {}

    /**
     * @return array<string, mixed>
     */
    public function for(string $companyId, StockFilters $f): array
    {
        $layers = $this->layers($companyId, $f);
        $shops = DB::table('branches')->where('company_id', $companyId)->pluck('name', 'id');
        $departments = DB::table('departments')->where('company_id', $companyId)->pluck('name', 'id');

        $totals = ['lines' => 0, 'fifo' => '0', 'cost' => '0', 'basis' => ['fifo' => 0, 'mixed' => 0, 'cost' => 0, 'none' => 0]];
        $byShop = [];
        $byDepartment = [];
        $top = [];

        $cursor = $this->lines->base($companyId, $f)->where('bp.qty_on_hand', '>', 0)
            ->select(['bp.id', 'bp.branch_id', 'bp.product_id', 'bp.qty_on_hand', 'p.name', 'p.sku', 'p.cost_price', 'p.department_id'])
            ->orderBy('bp.id')->cursor();

        foreach ($cursor as $r) {
            $key = $r->branch_id.'|'.$r->product_id;
            $fifo = FifoValuation::of($r->qty_on_hand, $layers[$key] ?? [], $r->cost_price);
            $atCost = Money::round(Money::mul($r->qty_on_hand, $r->cost_price ?? 0, 4), 2);

            $totals['lines']++;
            $totals['fifo'] = Money::add($totals['fifo'], $fifo['value']);
            $totals['cost'] = Money::add($totals['cost'], $atCost);
            $totals['basis'][$fifo['basis']]++;

            self::addTo($byShop, (string) $r->branch_id, (string) ($shops[$r->branch_id] ?? 'Unknown shop'), $fifo['value'], $atCost);
            self::addTo($byDepartment, (string) ($r->department_id ?? ''), (string) ($departments[$r->department_id ?? ''] ?? 'No department'), $fifo['value'], $atCost);

            $top[] = [
                'id' => (string) $r->id,
                'productId' => (string) $r->product_id,
                'name' => (string) ($r->name ?? '') !== '' ? (string) $r->name : 'Unknown product',
                'sku' => (string) ($r->sku ?? ''),
                'shop' => (string) ($shops[$r->branch_id] ?? 'Unknown shop'),
                'onHand' => Money::normalise($r->qty_on_hand, 4),
                'fifoValue' => $fifo['value'],
                'costValue' => $atCost,
                'fifoQty' => $fifo['fifoQty'],
                'basis' => $fifo['basis'],
            ];

            if (count($top) > self::TOP * 4) {
                $top = self::best($top);
            }
        }

        $sort = function (array $rows): array {
            usort($rows, fn (array $a, array $b) => Money::compare($b['fifo'], $a['fifo']) ?: strcmp($a['name'], $b['name']));

            return $rows;
        };

        return [
            'totals' => [
                'lines' => $totals['lines'],
                'fifoValue' => Money::normalise($totals['fifo']),
                'costValue' => Money::normalise($totals['cost']),
                'difference' => Money::sub($totals['fifo'], $totals['cost']),
                'basis' => $totals['basis'],
                'layerRows' => array_sum(array_map('count', $layers)),
            ],
            'byShop' => $sort($byShop),
            'byDepartment' => $sort($byDepartment),
            'top' => self::best($top),
        ];
    }

    /**
     * The layers with something left, per `branch|product`.
     *
     * @return array<string, list<array{qty: string, cost: string, at: string|null, id: string}>>
     */
    public function layers(string $companyId, StockFilters $f): array
    {
        $out = [];
        $rows = DB::table('fifo_stock_layers')->where('company_id', $companyId)->whereNull('deleted_at')->where('qty_remaining', '>', 0)
            ->when($f->shop !== null, fn ($q) => $q->where('branch_id', $f->shop))
            ->when($f->product !== null, fn ($q) => $q->where('product_id', $f->product))
            ->get(['id', 'branch_id', 'product_id', 'qty_remaining', 'unit_cost', 'received_at']);

        foreach ($rows as $l) {
            $out[$l->branch_id.'|'.$l->product_id][] = [
                'qty' => Money::normalise($l->qty_remaining, 4),
                'cost' => Money::normalise($l->unit_cost ?? 0, 4),
                'at' => $l->received_at !== null ? (string) $l->received_at : null,
                'id' => (string) $l->id,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, array{id: string, name: string, lines: int, fifo: string, cost: string}>  $groups
     */
    private static function addTo(array &$groups, string $id, string $name, string $fifo, string $cost): void
    {
        $groups[$id] ??= ['id' => $id, 'name' => $name, 'lines' => 0, 'fifo' => '0.00', 'cost' => '0.00'];
        $groups[$id]['lines']++;
        $groups[$id]['fifo'] = Money::add($groups[$id]['fifo'], $fifo);
        $groups[$id]['cost'] = Money::add($groups[$id]['cost'], $cost);
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array<string, string>>
     */
    private static function best(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => Money::compare($b['fifoValue'], $a['fifoValue']) ?: strcmp($a['id'], $b['id']));

        return array_slice($rows, 0, self::TOP);
    }
}
