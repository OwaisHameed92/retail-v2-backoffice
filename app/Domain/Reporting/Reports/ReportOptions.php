<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Reporting\Dashboard\BusinessDashboardFilters;
use App\Domain\Reporting\Data\ReportScope;

/**
 * What one report shows: the business dashboard's window (dates, compare, the shop — a one-shop user's own, always —
 * and till), plus the report's own grouping, tab (`view`) and page. `export` = every row (CSV, print), no paging.
 */
final readonly class ReportOptions
{
    public const PAGE_SIZE = 50;

    public const EXPORT_LIMIT = 20000;

    public function __construct(
        public BusinessDashboardFilters $window,
        public ReportGrouping $group = ReportGrouping::Day,
        public string $view = '',
        public int $page = 1,
        public bool $export = false,
    ) {}

    public function scope(): ReportScope
    {
        return $this->window->scope();
    }

    public function compareScope(): ?ReportScope
    {
        return $this->window->compareScope();
    }

    public function companyId(): string
    {
        return $this->window->companyId;
    }

    /** Rows of a list: one page on screen, everything (up to EXPORT_LIMIT) for CSV and print. */
    public function limit(): int
    {
        return $this->export ? self::EXPORT_LIMIT : self::PAGE_SIZE;
    }

    public function offset(): int
    {
        return $this->export ? 0 : (max(1, $this->page) - 1) * self::PAGE_SIZE;
    }

    public function asExport(): self
    {
        return new self($this->window, $this->group, $this->view, 1, true);
    }
}
