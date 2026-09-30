<?php

namespace App\Domain\Reporting\Actions;

use App\Domain\Reporting\Reports\ReportKind;
use App\Domain\Reporting\Reports\ReportOptions;
use App\Domain\Reporting\Reports\ReportResult;

/**
 * Builds one report of module 4.8 for the current company (screen, CSV or print). The options carry a tenant scope
 * (a one-shop user's shop already fixed by `BusinessContext`); every builder reads through it.
 */
final class BuildReport
{
    public function handle(ReportKind $kind, ReportOptions $options): ReportResult
    {
        return app($kind->builder())->build($options);
    }
}
