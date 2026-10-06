<?php

namespace App\Domain\Ai\Tools\Portal;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Support\Portal\ShopPin;
use App\Domain\Ai\Support\Portal\ToolWindow;
use App\Domain\Reporting\Data\DaySales;
use App\Domain\Reporting\Data\GroupSales;
use App\Domain\Reporting\Data\HourSales;
use App\Domain\Reporting\Data\SalesTotals;
use App\Domain\Reporting\Queries\SalesReport;
use App\Domain\Reporting\Reports\ReportKind;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\Enums\Ability;

/**
 * Read: sales totals for a period (rpt_sales_daily / hourly through the 3.1 SalesReport, like the dashboard and the
 * sales report), the change against a compare window, and an optional breakdown by shop, till, day or hour.
 */
final class GetSales extends PortalReadTool
{
    public const BREAKDOWNS = ['none', 'shop', 'till', 'day', 'hour'];

    public function name(): string
    {
        return 'get_sales';
    }

    public function description(): string
    {
        return Country::tax('Sales for a period: net (ex VAT), gross, VAT, takings, transactions, average basket, refunds, voids, '
            .'discounts and gross profit, with the change against a compare window. Optional breakdown by shop, till, '
            .'day or hour of the day (busy hours). Use it for any "how much did we sell / takings / busiest hour" question.');
    }

    public function inputSchema(): array
    {
        return self::object([
            ...ToolWindow::properties(compare: true),
            'breakdown' => ['type' => 'string', 'enum' => self::BREAKDOWNS, 'description' => 'Split the figures. Default none.'],
        ]);
    }

    public function rules(): array
    {
        return [...ToolWindow::rules(compare: true), 'breakdown' => ['nullable', 'string', 'in:'.implode(',', self::BREAKDOWNS)]];
    }

    public function requiredAbility(): Ability
    {
        return Ability::ReportsView;
    }

    public function handle(array $input, AiContext $context): array
    {
        $shop = ShopPin::resolve($input['shop_id'] ?? null);
        $window = ToolWindow::filters($input, $shop);
        $report = app(SalesReport::class);
        $scope = $window->scope();
        $current = $report->totals($scope);
        $compareScope = $window->compareScope();
        $breakdown = (string) ($input['breakdown'] ?? 'none');

        $data = [
            ...$shop->toArray(),
            'period' => ToolWindow::describe($window),
            'totals' => self::totals($current),
        ];

        if ($compareScope !== null) {
            $data['compare'] = [
                'with' => $window->compare->label(),
                'period' => ToolWindow::label($compareScope->from, $compareScope->to),
                'totals' => self::totals($report->totals($compareScope)),
            ];
        }

        $data['breakdown'] = match ($breakdown) {
            'shop' => array_map(self::group(...), $report->byBranch($scope)),
            'till' => array_map(self::group(...), $report->byRegister($scope)),
            'day' => array_map(fn (DaySales $d) => ['day' => $d->day, 'net' => $d->net, 'gross' => $d->gross, 'transactions' => $d->transactions], $report->byDay($scope)),
            'hour' => array_values(array_filter(array_map(
                fn (HourSales $h) => ['hour' => sprintf('%02d:00', $h->hour), 'net' => $h->net, 'transactions' => $h->transactions],
                $report->byHour($scope),
            ), fn (array $h) => $h['transactions'] > 0)),
            default => null,
        };

        $data = array_filter($data, fn (mixed $v) => $v !== null);
        $this->links->report($breakdown === 'hour' ? ReportKind::Hourly : ReportKind::Sales, $window, $shop);

        return $data;
    }

    /**
     * @return array<string, string|int|null>
     */
    private static function totals(SalesTotals $t): array
    {
        return [
            'net' => $t->net,
            'gross' => $t->gross,
            'vat' => $t->vat,
            'takings' => $t->takings,
            'transactions' => $t->transactions,
            'averageBasketIncVat' => $t->averageBasketIncVat(),
            'refunds' => $t->refundCount,
            'refundedGross' => $t->refundGross,
            'voids' => $t->voidCount,
            'voidedTotal' => $t->voidTotal,
            'discounts' => $t->discount,
            'grossProfit' => $t->grossProfit(),
        ];
    }

    /**
     * @return array<string, string|int|null>
     */
    private static function group(GroupSales $g): array
    {
        return ['name' => $g->label, 'net' => $g->net, 'gross' => $g->gross, 'takings' => $g->takings, 'transactions' => $g->transactions, 'averageBasketExVat' => $g->averageBasketExVat];
    }
}
