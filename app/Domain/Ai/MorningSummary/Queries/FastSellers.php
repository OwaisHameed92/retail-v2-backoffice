<?php

namespace App\Domain\Ai\MorningSummary\Queries;

use App\Domain\Notifications\Queries\StockDigest;
use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Models\RptProductDaily;
use App\Domain\Reporting\Queries\Sums;
use App\Domain\Reporting\ReportTables;
use App\Domain\Reporting\Support\Units;
use App\Domain\Shared\Support\Money;
use App\Domain\Stock\Data\StockFilters;
use App\Domain\Stock\Queries\StockLines;
use Carbon\CarbonImmutable;

/**
 * "Low stock that matters" (module 6.3): stock lines at or below their low-stock point (the till's rule, StockLines,
 * as the Stock on hand screen) that sold at least {@see self::MIN_WEEK_QTY} in the 7 days to yesterday, the
 * fastest first, at most {@see self::PER_SHOP} per shop. Run as the business (MorningFacts sets CurrentCompany).
 */
final class FastSellers
{
    public const MIN_WEEK_QTY = 7;

    public const PER_SHOP = 5;

    public function __construct(private readonly StockLines $lines) {}

    /**
     * @param  array<string, string>  $shops  active shop names by id
     * @return array<string, list<array{name: string, onHand: string, soldWeek: string}>>
     */
    public function for(string $companyId, array $shops, CarbonImmutable $day): array
    {
        [$t, $bindings] = $this->lines->threshold($companyId);
        $low = $this->lines->base($companyId, new StockFilters)->whereRaw(StockLines::condition('low', $t), $bindings)
            ->whereIn('bp.branch_id', array_keys($shops))
            ->select(['bp.branch_id', 'bp.product_id', 'p.name', 'bp.qty_on_hand'])->limit(5000)->get();

        if ($low->isEmpty()) {
            return [];
        }

        $sold = $this->soldWeek($day);
        $out = [];

        foreach ($low as $line) {
            $branch = (string) $line->branch_id;
            $week = $sold[$branch][(string) $line->product_id] ?? '0';

            if (Money::compare($week, (string) self::MIN_WEEK_QTY) < 0) {
                continue;
            }

            $out[$branch][] = ['name' => trim((string) $line->name), 'onHand' => StockDigest::qty($line->qty_on_hand), 'soldWeek' => StockDigest::qty($week)];
        }

        foreach ($out as $branch => $items) {
            usort($items, fn (array $a, array $b) => Money::compare($b['soldWeek'], $a['soldWeek']) ?: strcmp($a['name'], $b['name']));
            $out[$branch] = array_slice($items, 0, self::PER_SHOP);
        }

        return $out;
    }

    /**
     * Quantity sold less returned per shop and product, the 7 days to yesterday.
     *
     * @return array<string, array<string, string>>
     */
    private function soldWeek(CarbonImmutable $day): array
    {
        $t = ReportTables::PRODUCT_DAILY;
        $sums = ['qty' => 4, 'refund_qty' => 4];
        $out = [];

        $rows = ReportScope::tenant($day->subDays(6), $day)->query(RptProductDaily::class)
            ->groupBy("{$t}.branch_id", "{$t}.product_id")
            ->select(["{$t}.branch_id", "{$t}.product_id", ...Sums::select($t, $sums)])->get();

        foreach ($rows as $row) {
            $s = Sums::read($row, $sums);
            $out[(string) $row->branch_id][(string) $row->product_id] = Units::decimal(
                Units::sub(Units::fromDecimal($s['qty'], 4), Units::fromDecimal($s['refund_qty'], 4)), 4,
            );
        }

        return $out;
    }
}
