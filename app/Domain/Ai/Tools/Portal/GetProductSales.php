<?php

namespace App\Domain\Ai\Tools\Portal;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Support\Portal\ShopPin;
use App\Domain\Ai\Support\Portal\ToolWindow;
use App\Domain\Reporting\Data\LineGroupSales;
use App\Domain\Reporting\Data\ProductSales;
use App\Domain\Reporting\Queries\ProductReport;
use App\Domain\Reporting\Reports\Figures;
use App\Domain\Reporting\Reports\ReportKind;
use App\Domain\Tenancy\Enums\Ability;

/**
 * Read: best and worst sellers, or sales per department, for a period (rpt_product_daily through the 3.1
 * ProductReport, as the dashboard's "Top products" and the product sales report).
 */
final class GetProductSales extends PortalReadTool
{
    /** Worst sellers are ranked over at most this many selling products. */
    private const RANK_POOL = 5000;

    public function name(): string
    {
        return 'get_product_sales';
    }

    public function description(): string
    {
        return 'Product sales for a period: the top (best) or bottom (worst) selling products ranked by net sales or '
            .'quantity, or sales per department. Each product has quantity sold (net of returns), net and gross sales, '
            .'gross profit and margin. Products that sold nothing in the period are not listed.';
    }

    public function inputSchema(): array
    {
        return self::object([
            ...ToolWindow::properties(),
            'view' => ['type' => 'string', 'enum' => ['top', 'bottom', 'departments'], 'description' => 'Default top.'],
            'rank_by' => ['type' => 'string', 'enum' => ['net', 'qty'], 'description' => 'Rank by net sales (default) or quantity.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'description' => 'How many products. Default 10.'],
        ]);
    }

    public function rules(): array
    {
        return [
            ...ToolWindow::rules(),
            'view' => ['nullable', 'string', 'in:top,bottom,departments'],
            'rank_by' => ['nullable', 'string', 'in:net,qty'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ];
    }

    public function requiredAbility(): Ability
    {
        return Ability::ReportsView;
    }

    public function handle(array $input, AiContext $context): array
    {
        $shop = ShopPin::resolve($input['shop_id'] ?? null);
        $window = ToolWindow::filters($input, $shop);
        $report = app(ProductReport::class);
        $view = (string) ($input['view'] ?? 'top');
        $rankBy = ($input['rank_by'] ?? 'net') === 'qty' ? 'qty' : 'net';
        $limit = (int) ($input['limit'] ?? 10);

        $data = [...$shop->toArray(), 'period' => ToolWindow::describe($window), 'view' => $view, 'rankedBy' => $rankBy];

        if ($view === 'departments') {
            $data['departments'] = array_map(fn (LineGroupSales $g) => [
                'department' => $g->name, 'qty' => $g->qty, 'net' => $g->net, 'gross' => $g->gross,
            ], $report->byDepartment($window->scope()));
        } else {
            $rows = $view === 'bottom'
                ? array_slice(array_reverse($report->top($window->scope(), self::RANK_POOL, $rankBy)), 0, $limit)
                : $report->top($window->scope(), $limit, $rankBy);

            $data['products'] = array_map(fn (ProductSales $p) => [
                'productId' => $p->productId, 'name' => $p->name, 'department' => $p->department, 'qty' => $p->qty,
                'net' => $p->net, 'gross' => $p->gross, 'grossProfit' => Figures::profit($p->net, $p->cost),
                'marginPercent' => Figures::margin($p->net, $p->cost),
            ], $rows);
        }

        $this->links->report(ReportKind::Products, $window, $shop);

        return $data;
    }
}
