<?php

namespace App\Domain\Purchasing\Reorder;

use App\Domain\Purchasing\Reorder\Sources\LeadTimes;
use App\Domain\Purchasing\Reorder\Sources\SeasonalFactors;
use App\Domain\Purchasing\Reorder\Sources\ShopFigures;
use App\Domain\Purchasing\Reorder\Sources\SupplierLinks;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Reorder suggestions for the current business (module 6.4): for each open shop (or the one asked for; a one-shop
 * user's own) and each product linked to a supplier with a stock line there, the cases to order now, grouped by shop
 * and supplier (one purchase order each). ReorderCalculator does the maths; this class gathers the inputs in bulk.
 *
 * @phpstan-type Group array{key: string, shopId: string, shopName: string, supplierId: string, supplierName: string, leadDays: int, leadBasis: string, leadSamples: int, reviewDays: int, minimumOrder: string|null}
 */
final class ReorderSuggestions
{
    /** Flags that put a line under "Worth a look" (overstock is shown on the line but is not a reordering question). */
    public const ATTENTION = ['negativeStock', 'runsOut', 'spike', 'slowing', 'shortLife', 'wasteRisk', 'seasonal'];

    public const NOTABLE = 8;

    /**
     * `notable`: the lines worth a look whatever the view (the most pressing first, at most NOTABLE), `attention`: how many.
     *
     * @return array{lines: list<array<string, mixed>>, groups: list<Group>, truncated: bool, notable: list<array<string, mixed>>, attention: int}
     */
    public function handle(ReorderFilters $f, ?CarbonImmutable $today = null): array
    {
        $companyId = app(CurrentCompany::class)->id() ?? abort(404);
        $today ??= TradingDay::today();
        $links = SupplierLinks::chosen($companyId, $f->supplier);
        $shops = Branch::query()->where('is_active', true)->when($f->shop !== null, fn ($q) => $q->whereKey($f->shop))->orderBy('name')->get(['id', 'name']);
        [$lines, $groups] = [[], []];

        foreach ($shops as $shop) {
            foreach ($this->forShop($companyId, $shop, $links, $f, $today) as [$line, $group]) {
                $groups[$group['key']] ??= $group;
                $lines[] = $line;
            }
        }

        $notable = self::notable($lines, PHP_INT_MAX);
        $lines = array_values(array_filter($lines, fn (array $l) => match ($f->view) {
            'order' => $l['suggestedCases'] > 0,
            'attention' => array_intersect($l['flags'], self::ATTENTION) !== [],
            default => true,
        }));

        usort($lines, fn (array $a, array $b) => [$a['shopName'], $a['supplierName'], ! in_array('runsOut', $a['flags'], true), $a['coverDays'] === null, (float) ($a['coverDays'] ?? 0), $a['name']]
            <=> [$b['shopName'], $b['supplierName'], ! in_array('runsOut', $b['flags'], true), $b['coverDays'] === null, (float) ($b['coverDays'] ?? 0), $b['name']]);

        $max = max(1, (int) config('reorder.max_lines', 600));
        $shown = array_slice($lines, 0, $max);
        $used = array_flip(array_column($shown, 'groupKey'));

        return [
            'lines' => $shown,
            'groups' => array_values(array_filter($groups, fn (array $g) => isset($used[$g['key']]))),
            'truncated' => count($lines) > $max,
            'notable' => array_slice($notable, 0, self::NOTABLE),
            'attention' => count($notable),
        ];
    }

    /**
     * Lines worth a look, the most pressing first.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public static function notable(array $lines, int $limit = self::NOTABLE): array
    {
        $rank = array_flip(['negativeStock', 'runsOut', 'wasteRisk', 'spike', 'shortLife', 'slowing', 'seasonal']);
        $scored = [];

        foreach ($lines as $line) {
            $hits = array_intersect_key($rank, array_flip((array) $line['flags']));

            if ($hits !== []) {
                $scored[] = [min($hits), $line];
            }
        }

        usort($scored, fn (array $a, array $b) => [$a[0], $a[1]['coverDays'] === null, (float) ($a[1]['coverDays'] ?? 0)] <=> [$b[0], $b[1]['coverDays'] === null, (float) ($b[1]['coverDays'] ?? 0)]);

        return array_slice(array_column($scored, 1), 0, $limit);
    }

    /**
     * @param  array<string, array{supplierId: string, supplierName: string, caseQty: int, unitCost: string|null, supplierSku: string|null, minimumOrder: string|null, defaultLeadDays: int|null}>  $links
     * @return list<array{0: array<string, mixed>, 1: Group}>
     */
    private function forShop(string $companyId, Branch $shop, array $links, ReorderFilters $f, CarbonImmutable $today): array
    {
        $products = $this->products($companyId, $shop->id, $f)->filter(fn (object $p) => isset($links[$p->id]))->values();

        if ($products->isEmpty()) {
            return [];
        }

        $ids = $products->pluck('id')->map(fn ($id) => (string) $id)->all();
        $suppliers = [];

        foreach ($ids as $id) {
            $suppliers[$links[$id]['supplierId']] = $links[$id]['defaultLeadDays'];
        }

        $review = max(1, (int) config('reorder.review_days', 7));
        $leads = LeadTimes::forShop($companyId, $shop->id, $suppliers);
        $horizon = max(array_column($leads, 'days')) + $review;
        $weeks = max(1, (int) config('reorder.history_weeks', 8));
        $sales = ShopFigures::dailySales($companyId, $shop->id, $ids, $today->subDays(7 * $weeks)->toDateString(), $today->subDay()->toDateString());
        $onOrder = ShopFigures::onOrder($companyId, $shop->id, $ids);
        $transit = ShopFigures::inTransit($companyId, $shop->id, $ids);
        $shelf = ShopFigures::shelfLives($companyId, $ids);
        $departments = [];
        foreach ($products as $p) {
            $departments[(string) $p->id] = $p->department_id !== null ? (string) $p->department_id : null;
        }
        $events = SeasonalFactors::forShop($companyId, $shop->id, $departments, $today, $horizon);
        $patternUnits = (int) config('reorder.weekday_pattern_units', 28);
        $out = [];

        foreach ($products as $p) {
            $id = (string) $p->id;
            $link = $links[$id];
            $lead = $leads[$link['supplierId']];
            $productEvents = array_values(array_filter($events[$id] ?? [], fn (array $e) => $e['from'] <= $today->addDays($lead['days'] + $review - 1)->toDateString()));
            $input = new ReorderInput(
                today: $today,
                demand: DemandForecast::fromHistory($sales[$id] ?? [], $today, $weeks, $patternUnits),
                caseQty: $link['caseQty'],
                onHand: self::qty($p->qty_on_hand) ?? '0',
                onOrder: $onOrder[$id] ?? '0',
                inTransit: $transit[$id] ?? '0',
                minLevel: self::qty($p->reorder_point ?? $p->min_qty ?? $p->min_stock_qty),
                maxLevel: self::qty($p->max_qty ?? $p->max_stock_qty),
                reorderQty: self::qty($p->reorder_qty),
                leadDays: $lead['days'],
                reviewDays: $review,
                safetyDays: max(0, (int) config('reorder.safety_days', 2)),
                shelfLifeDays: $shelf[$id] ?? null,
                events: $productEvents,
            );
            $result = ReorderCalculator::calculate($input);
            $unitCost = $link['unitCost'] ?? Money::normalise($p->cost_price ?? 0, 4);
            $groupKey = $shop->id.'|'.$link['supplierId'];

            $out[] = [[
                'key' => $shop->id.'|'.$id,
                'groupKey' => $groupKey,
                'shopId' => $shop->id,
                'shopName' => $shop->name,
                'supplierId' => $link['supplierId'],
                'supplierName' => $link['supplierName'],
                'productId' => $id,
                'name' => (string) ($p->name ?? '') !== '' ? (string) $p->name : 'Unknown product',
                'sku' => $p->sku !== null && $p->sku !== '' ? (string) $p->sku : null,
                'supplierSku' => $link['supplierSku'],
                'department' => $p->department !== null ? (string) $p->department : null,
                'caseQty' => $link['caseQty'],
                'unitCost' => $unitCost,
                'onHand' => $input->onHand,
                'onOrder' => Money::normalise($input->onOrder, 4),
                'inTransit' => Money::normalise($input->inTransit, 4),
                'minLevel' => $input->minLevel,
                'maxLevel' => $input->maxLevel,
                'rate' => $result->rate,
                'coverDays' => $result->coverDays,
                'forecast' => $result->forecast,
                'horizonDays' => $lead['days'] + $review,
                'shelfLifeDays' => $input->shelfLifeDays,
                'events' => array_values(array_unique(array_column($productEvents, 'name'))),
                'suggestedCases' => $result->cases,
                'suggestedUnits' => $result->units,
                'suggestedCost' => Money::round(Money::mul($result->units, $unitCost, 4), 2),
                'method' => $result->method,
                'flags' => $result->flags,
                'reasons' => $result->reasons,
            ], [
                'key' => $groupKey, 'shopId' => $shop->id, 'shopName' => $shop->name, 'supplierId' => $link['supplierId'],
                'supplierName' => $link['supplierName'], 'leadDays' => $lead['days'], 'leadBasis' => $lead['basis'], 'leadSamples' => $lead['samples'],
                'reviewDays' => $review, 'minimumOrder' => $link['minimumOrder'],
            ]];
        }

        return $out;
    }

    /**
     * Active, stock-tracked products with a stock line at the shop.
     *
     * @return Collection<int, stdClass>
     */
    private function products(string $companyId, string $shopId, ReorderFilters $f): Collection
    {
        return DB::table('products as p')
            ->join('branch_products as bp', fn ($j) => $j->on('bp.product_id', '=', 'p.id')->where('bp.company_id', $companyId)->where('bp.branch_id', $shopId)->whereNull('bp.deleted_at'))
            ->leftJoin('departments as d', fn ($j) => $j->on('d.id', '=', 'p.department_id')->where('d.company_id', $companyId))
            ->where('p.company_id', $companyId)->whereNull('p.deleted_at')->whereNull('p.archived_at')
            ->where(fn ($q) => $q->where('p.is_active', true)->orWhereNull('p.is_active'))
            ->where(fn ($q) => $q->where('p.track_stock', true)->orWhereNull('p.track_stock'))
            ->where(fn ($q) => $q->where('bp.is_stock_tracked', true)->orWhereNull('bp.is_stock_tracked'))
            ->whereIn('p.id', DB::table('product_suppliers')->where('company_id', $companyId)->whereNull('deleted_at')->select('product_id'))
            ->when($f->department !== null, fn ($q) => $q->where('p.department_id', $f->department))
            ->when($f->search !== null, fn ($q) => $q->where(fn ($w) => $w->where('p.name', 'like', '%'.$f->search.'%')->orWhere('p.sku', 'like', '%'.$f->search.'%')))
            ->orderBy('p.name')
            ->get(['p.id', 'p.name', 'p.sku', 'p.department_id', 'd.name as department', 'p.cost_price', 'p.min_stock_qty', 'p.max_stock_qty', 'p.reorder_qty',
                'bp.qty_on_hand', 'bp.reorder_point', 'bp.min_qty', 'bp.max_qty']);
    }

    private static function qty(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Money::normalise($value, 4);
    }
}
