<?php

namespace App\Domain\Ai\Tools\Portal;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Support\Portal\ReportDigest;
use App\Domain\Ai\Support\Portal\ShopPin;
use App\Domain\Ai\Support\Portal\ToolWindow;
use App\Domain\Reporting\Reports\ReportKind;
use App\Domain\Tenancy\Enums\Ability;

/**
 * Base of the read tools that are one report of module 4.8 (BuildReport, the same figures as the linked page), cut
 * down by ReportDigest: refunds and voids, the VAT summary, cash variances (shifts and Z reports).
 */
abstract class GetReportFigures extends PortalReadTool
{
    abstract protected function report(): ReportKind;

    /** @return list<string>|null the report's tables to include (null = all) */
    protected function tables(): ?array
    {
        return null;
    }

    public function inputSchema(): array
    {
        return self::object(ToolWindow::properties(compare: $this->report()->compares()));
    }

    public function rules(): array
    {
        return ToolWindow::rules(compare: $this->report()->compares());
    }

    public function requiredAbility(): Ability
    {
        return Ability::ReportsView;
    }

    public function handle(array $input, AiContext $context): array
    {
        $shop = ShopPin::resolve($input['shop_id'] ?? null);
        $window = ToolWindow::filters($input, $shop);
        $data = [...$shop->toArray(), 'period' => ToolWindow::describe($window)];

        if ($this->report()->compares() && $window->compareScope() !== null) {
            $data['comparedWith'] = $window->compare->label();
        }

        $this->links->report($this->report(), $window, $shop);

        return [...$data, ...ReportDigest::run($this->report(), $window, tables: $this->tables())];
    }
}
