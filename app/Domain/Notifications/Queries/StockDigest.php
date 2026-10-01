<?php

namespace App\Domain\Notifications\Queries;

use App\Domain\Shared\Support\Money;
use App\Domain\Stock\Data\StockFilters;
use App\Domain\Stock\Queries\StockLines;

/**
 * Low-stock part of the daily digest (module 7.8): per shop, the stock lines at or below their low-stock point (the
 * till's rule, StockLines, as the Stock on hand screen), how many are out or below zero, and the worst few.
 */
final class StockDigest
{
    public function __construct(private readonly StockLines $lines) {}

    /**
     * @param  array<string, string>  $shops  active shop names by id
     * @return array<string, array{total: int, counts: array<string, int>, items: list<string>}>
     */
    public function for(string $companyId, array $shops): array
    {
        [$t, $bindings] = $this->lines->threshold($companyId);
        $low = StockLines::condition('low', $t);
        $rows = $this->lines->base($companyId, new StockFilters)->whereRaw($low, $bindings)->whereIn('bp.branch_id', array_keys($shops))
            ->groupBy('bp.branch_id')
            ->selectRaw('bp.branch_id, COUNT(*) as low_count, SUM(CASE WHEN COALESCE(bp.qty_on_hand, 0) <= 0 THEN 1 ELSE 0 END) as out_count, '
                .'SUM(CASE WHEN COALESCE(bp.qty_on_hand, 0) < 0 THEN 1 ELSE 0 END) as negative_count')
            ->get();
        $out = [];

        foreach ($rows as $row) {
            $branchId = (string) $row->branch_id;
            $items = $this->lines->base($companyId, new StockFilters(shop: $branchId))->whereRaw($low, $bindings)
                ->select(['p.name', 'bp.qty_on_hand'])->selectRaw("{$t} as threshold", $bindings)
                ->orderByRaw('CASE WHEN COALESCE(bp.qty_on_hand, 0) < 0 THEN 0 WHEN COALESCE(bp.qty_on_hand, 0) <= 0 THEN 1 ELSE 2 END')
                ->orderBy('bp.qty_on_hand')->orderBy('p.name')->limit(DigestFindings::ITEMS)->get()
                ->map(fn (object $line) => $shops[$branchId].': '.trim((string) $line->name).', '.self::qty($line->qty_on_hand).' on hand'
                    .(Money::compare(Money::normalise($line->qty_on_hand ?? 0, 4), '0') > 0 ? ' (low at '.self::qty($line->threshold).')' : ''))
                ->values()->all();

            $out[$branchId] = [
                'total' => (int) $row->low_count,
                'counts' => ['low' => (int) $row->low_count, 'out' => (int) $row->out_count, 'negative' => (int) $row->negative_count],
                'items' => $items,
            ];
        }

        return $out;
    }

    /** "3.5000" → "3.5", "-2.0000" → "-2" */
    public static function qty(mixed $value): string
    {
        $text = Money::normalise($value ?? 0, 4);

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
