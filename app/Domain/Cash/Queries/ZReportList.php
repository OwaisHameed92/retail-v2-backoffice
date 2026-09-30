<?php

namespace App\Domain\Cash\Queries;

use App\Domain\Cash\Data\CashFilters;
use App\Domain\Cash\Support\CashLookup;
use App\Domain\Cash\Support\ZTotals;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Models\ZReport;
use Illuminate\Http\Request;

/**
 * Z reports per till and trading day (module 5.4): the Z reports whose period ended in the chosen London days,
 * newest first, and one Z report with the till's own figures from `totalsJson` (never recomputed).
 */
final class ZReportList
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request, CashFilters $filters): array
    {
        $table = TableQuery::from($request)->sortable(['period_end', 'sequence_no'])->defaultSort('period_end', 'desc')->defaultPerPage(25);
        $page = $table->paginator($filters->during($filters->scope(ZReport::query()), 'period_end'));
        /** @var list<ZReport> $zs */
        $zs = $page->items();
        $shops = CashLookup::shops(array_map(fn (ZReport $z) => $z->branch_id, $zs));
        $tills = CashLookup::tills(array_map(fn (ZReport $z) => $z->register_id, $zs));

        return [
            'reports' => [
                'data' => array_map(function (ZReport $z) use ($shops, $tills) {
                    $totals = ZTotals::parse($z->totals_json);

                    return [
                        'id' => $z->id,
                        'sequenceNo' => $z->sequence_no,
                        'day' => TradingDay::of($z->period_end)[0],
                        'shop' => CashLookup::name($shops, $z->branch_id),
                        'till' => CashLookup::name($tills, $z->register_id),
                        'periodStart' => CashLookup::iso($z->period_start),
                        'periodEnd' => CashLookup::iso($z->period_end),
                        'printedAt' => CashLookup::iso($z->printed_at),
                        'reprints' => $z->reprint_count,
                        'variance' => $totals->variance,
                        'warning' => $totals->warning,
                        'shiftId' => $z->shift_id !== '' ? $z->shift_id : null,
                    ];
                }, $zs),
                'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage(), 'search' => null, 'sort' => $table->sort(), 'direction' => $table->direction()],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function show(ZReport $z): array
    {
        $staff = CashLookup::staff([$z->printed_by]);

        return [
            'report' => [
                'id' => $z->id,
                'sequenceNo' => $z->sequence_no,
                'day' => TradingDay::of($z->period_end)[0],
                'shop' => CashLookup::name(CashLookup::shops([$z->branch_id]), $z->branch_id),
                'till' => CashLookup::name(CashLookup::tills([$z->register_id]), $z->register_id),
                'periodStart' => CashLookup::iso($z->period_start),
                'periodEnd' => CashLookup::iso($z->period_end),
                'generatedAt' => CashLookup::iso($z->generated_at),
                'printedAt' => CashLookup::iso($z->printed_at),
                'printedBy' => CashLookup::name($staff, $z->printed_by),
                'reprints' => $z->reprint_count,
                'shiftId' => $z->shift_id !== '' ? $z->shift_id : null,
                'totals' => ZTotals::parse($z->totals_json)->toArray(),
            ],
        ];
    }
}
