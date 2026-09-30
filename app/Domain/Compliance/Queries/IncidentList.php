<?php

namespace App\Domain\Compliance\Queries;

use App\Domain\Compliance\Data\ComplianceFilters;
use App\Domain\Compliance\Support\ComplianceLookup as L;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Models\IncidentReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The incident log (module 5.7): the tills' `IncidentReport` rows (theft, abuse, accident…) that happened in the
 * chosen days, newest first, with counts by category and one incident in full. Read only: the shop owns them.
 */
final class IncidentList
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request, ComplianceFilters $f): array
    {
        $table = TableQuery::from($request)->searchable(['description', 'police_reference', 'insurer_reference'])
            ->sortable(['occurred_at', 'category'])->defaultSort('occurred_at', 'desc')->defaultPerPage(25);
        $page = $table->paginator(self::base($f)->when($f->type !== null, fn (Builder $q) => $q->where('category', $f->type)));
        /** @var list<IncidentReport> $rows */
        $rows = $page->items();
        $shops = L::shops(array_map(fn (IncidentReport $r) => $r->branch_id, $rows));
        $staff = L::staff(array_map(fn (IncidentReport $r) => $r->reported_by_user_id, $rows));
        $categories = self::base($f)->groupBy('category')->select(['category', DB::raw('count(*) as n')])->orderByDesc('n')->toBase()->get();

        return [
            'incidents' => [
                'data' => array_map(fn (IncidentReport $r) => [
                    'id' => $r->id,
                    'occurredAt' => L::iso($r->occurred_at),
                    'category' => L::blank($r->category),
                    'description' => L::blank($r->description),
                    'shop' => L::name($shops, $r->branch_id),
                    'reportedBy' => L::name($staff, $r->reported_by_user_id),
                    'police' => L::blank($r->police_reference),
                    'insurer' => L::blank($r->insurer_reference),
                ], $rows),
                'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage(), 'search' => $table->search(), 'sort' => $table->sort(), 'direction' => $table->direction()],
            ],
            'categories' => $categories->map(fn ($c) => ['value' => (string) $c->category, 'label' => L::blank((string) $c->category) ?? 'Uncategorised', 'count' => (int) $c->n])->values()->all(),
            'summary' => [
                'total' => (int) $categories->sum('n'),
                'police' => self::base($f)->where('police_reference', '!=', '')->whereNotNull('police_reference')->count(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function show(IncidentReport $r): array
    {
        return [
            'incident' => [
                'id' => $r->id,
                'occurredAt' => L::iso($r->occurred_at),
                'recordedAt' => L::iso($r->created_at),
                'category' => L::blank($r->category),
                'description' => L::blank($r->description),
                'shop' => L::name(L::shops([$r->branch_id]), $r->branch_id),
                'reportedBy' => L::name(L::staff([$r->reported_by_user_id]), $r->reported_by_user_id),
                'police' => L::blank($r->police_reference),
                'insurer' => L::blank($r->insurer_reference),
            ],
        ];
    }

    /** @return Builder<IncidentReport> */
    private static function base(ComplianceFilters $f): Builder
    {
        return $f->during($f->scope(IncidentReport::query()), 'occurred_at')
            ->when($f->staff !== null, fn (Builder $q) => $q->where('reported_by_user_id', $f->staff));
    }
}
