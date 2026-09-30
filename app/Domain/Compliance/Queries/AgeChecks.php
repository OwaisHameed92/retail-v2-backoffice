<?php

namespace App\Domain\Compliance\Queries;

use App\Domain\Compliance\Data\ComplianceFilters;
use App\Domain\Compliance\Support\ComplianceLookup as L;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Enums\SaleStatus;
use App\Domain\TillData\Enums\SaleType;
use App\Domain\TillData\Models\AgeRefusal;
use App\Domain\TillData\Models\SaleLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Age checks and refusals (module 5.7). A **check** is a completed sale (or exchange) with at least one age-restricted
 * line sold (`SaleLine.isAgeRestricted`, quantity above 0): the till asked and the customer passed. A **refusal** is
 * the till's `AgeRefusal` row. Refusal rate = refusals / (checks + refusals). Broken down by shop, staff member (the
 * sale's cashier, the refusal's operator), age rule (the product's rule; a refusal's own) and product.
 */
final class AgeChecks
{
    private const TOP = 10;

    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request, ComplianceFilters $f): array
    {
        $table = TableQuery::from($request)->sortable(['at'])->defaultSort('at', 'desc')->defaultPerPage(25);
        $page = $table->paginator(self::refusals($f));
        /** @var list<AgeRefusal> $rows */
        $rows = $page->items();
        $shops = L::shops(array_map(fn (AgeRefusal $r) => $r->branch_id, $rows));
        $tills = L::tills(array_map(fn (AgeRefusal $r) => $r->register_id, $rows));
        $staff = L::staff(array_map(fn (AgeRefusal $r) => $r->user_id, $rows));

        $checks = (int) self::checks($f)->distinct()->count('s.id');
        $refusals = (int) self::refusals($f)->count();

        return [
            'summary' => ['checks' => $checks, 'refusals' => $refusals, 'rate' => L::rate($refusals, $checks + $refusals)],
            'byShop' => self::breakdown($f, 's.branch_id', 'branch_id', fn (array $ids) => L::shops($ids), 'Unknown shop'),
            'byStaff' => self::breakdown($f, 's.user_id', 'user_id', fn (array $ids) => L::staff($ids), 'Unknown staff'),
            'byRule' => self::breakdown($f, 'p.age_rule', 'age_rule', fn (array $ids) => array_combine($ids, array_map(fn ($v) => L::ageRule($v), $ids)), 'No age rule'),
            'byProduct' => self::products($f),
            'refusalLog' => [
                'data' => array_map(fn (AgeRefusal $r) => [
                    'id' => $r->id,
                    'at' => L::iso($r->at),
                    'shop' => L::name($shops, $r->branch_id),
                    'till' => L::name($tills, $r->register_id),
                    'staff' => L::name($staff, $r->user_id) ?? L::blank($r->operator_name),
                    'product' => L::blank($r->product_name),
                    'rule' => $r->age_rule === null ? null : L::ageRule($r->age_rule->value),
                    'note' => L::blank($r->note),
                ], $rows),
                'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage(), 'search' => null, 'sort' => $table->sort(), 'direction' => $table->direction()],
            ],
            'rules' => L::ageRules(),
        ];
    }

    /**
     * Refusals in the chosen days, shop, staff member and rule.
     *
     * @return Builder<AgeRefusal>
     */
    public static function refusals(ComplianceFilters $f): Builder
    {
        return $f->during($f->scope(AgeRefusal::query()), 'at')
            ->when($f->staff !== null, fn (Builder $q) => $q->where('user_id', $f->staff))
            ->when($f->rule !== null, fn (Builder $q) => $q->where('age_rule', $f->rule));
    }

    /**
     * Age-restricted lines sold in completed sales of the chosen days, joined to their sale (`s`) and product (`p`).
     *
     * @return Builder<SaleLine>
     */
    public static function checks(ComplianceFilters $f): Builder
    {
        $query = SaleLine::query()
            ->join('sales as s', fn ($j) => $j->on('s.id', '=', 'sale_lines.sale_id')->on('s.company_id', '=', 'sale_lines.company_id'))
            ->leftJoin('products as p', fn ($j) => $j->on('p.id', '=', 'sale_lines.product_id')->on('p.company_id', '=', 'sale_lines.company_id'))
            ->where('sale_lines.is_age_restricted', true)->where('sale_lines.qty', '>', 0)
            ->where('s.status', SaleStatus::Completed->value)->whereIn('s.type', [SaleType::Sale->value, SaleType::Exchange->value])
            ->whereNull('s.deleted_at')
            ->when($f->shop !== null, fn (Builder $q) => $q->where('s.branch_id', $f->shop))
            ->when($f->staff !== null, fn (Builder $q) => $q->where('s.user_id', $f->staff))
            ->when($f->rule !== null, fn (Builder $q) => $q->where('p.age_rule', $f->rule));

        return $f->during($query, 's.completed_at');
    }

    /**
     * Checks and refusals side by side for one dimension, most refusals first.
     *
     * @param  callable(list<string>): array<string, string>  $names
     * @return list<array{key: string|null, label: string, checks: int, refusals: int, rate: string|null}>
     */
    private static function breakdown(ComplianceFilters $f, string $checkColumn, string $refusalColumn, callable $names, string $unknown): array
    {
        $checks = self::checks($f)->groupBy($checkColumn)->select([DB::raw("{$checkColumn} as k"), DB::raw('count(distinct s.id) as n')])
            ->toBase()->pluck('n', 'k')->all();
        $refusals = self::refusals($f)->groupBy($refusalColumn)->select([DB::raw("{$refusalColumn} as k"), DB::raw('count(*) as n')])
            ->toBase()->pluck('n', 'k')->all();
        $keys = array_values(array_unique(array_map('strval', [...array_keys($checks), ...array_keys($refusals)])));
        $labels = $names(array_values(array_filter($keys, fn ($k) => $k !== '')));

        $rows = array_map(function (string $k) use ($checks, $refusals, $labels, $unknown) {
            $c = (int) ($checks[$k] ?? 0);
            $r = (int) ($refusals[$k] ?? 0);

            return ['key' => $k !== '' ? $k : null, 'label' => $labels[$k] ?? $unknown, 'checks' => $c, 'refusals' => $r, 'rate' => L::rate($r, $c + $r)];
        }, $keys);

        usort($rows, fn ($a, $b) => [$b['refusals'], $b['checks'], $a['label']] <=> [$a['refusals'], $a['checks'], $b['label']]);

        return $rows;
    }

    /**
     * The products refused most (with how often they passed a check).
     *
     * @return list<array{key: string|null, label: string, checks: int, refusals: int, rate: string|null}>
     */
    private static function products(ComplianceFilters $f): array
    {
        $top = self::refusals($f)->groupBy('product_id')
            ->select(['product_id', DB::raw('max(product_name) as name'), DB::raw('count(*) as n')])
            ->orderByDesc('n')->limit(self::TOP)->toBase()->get();
        $ids = $top->pluck('product_id')->filter()->map(fn ($id) => (string) $id)->values()->all();
        $checks = $ids === [] ? [] : self::checks($f)->whereIn('sale_lines.product_id', $ids)->groupBy('sale_lines.product_id')
            ->select(['sale_lines.product_id as k', DB::raw('count(distinct s.id) as n')])->toBase()->pluck('n', 'k')->all();

        return $top->map(function ($row) use ($checks) {
            $c = (int) ($checks[(string) $row->product_id] ?? 0);

            return [
                'key' => L::blank((string) $row->product_id),
                'label' => L::blank((string) $row->name) ?? 'Unknown product',
                'checks' => $c,
                'refusals' => (int) $row->n,
                'rate' => L::rate((int) $row->n, $c + (int) $row->n),
            ];
        })->values()->all();
    }
}
