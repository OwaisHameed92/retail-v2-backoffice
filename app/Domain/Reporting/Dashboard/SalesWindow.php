<?php

namespace App\Domain\Reporting\Dashboard;

use App\Domain\Reporting\Data\ReportScope;

/**
 * What a trading dashboard shows (admin 3.2, business 3.3): the report scope of the chosen trading days, the compare
 * window (DASHBOARD.md §2.9) and the local hour now. {@see DashboardKpis} and {@see DashboardSeries} read through it,
 * so both panels build their tiles and charts the same way.
 */
interface SalesWindow
{
    public function scope(): ReportScope;

    /** Null when the viewer chose "No comparison". */
    public function compareScope(): ?ReportScope;

    /** A range of just today: compare up to the same hour, charts by hour, the current hour is "so far". */
    public function isToday(): bool;

    public function singleDay(): bool;

    /** The Europe/London hour (0–23) the figures were read at. */
    public function currentHour(): int;
}
