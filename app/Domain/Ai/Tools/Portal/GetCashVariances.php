<?php

namespace App\Domain\Ai\Tools\Portal;

use App\Domain\Reporting\Reports\ReportKind;
use App\Domain\Tenancy\Enums\Ability;

/**
 * Read: closed shifts with expected against counted cash and their variance (the "Shifts and Z reports" report,
 * module 4.8, from the till's own shift rows).
 */
final class GetCashVariances extends GetReportFigures
{
    public function name(): string
    {
        return 'get_cash_variances';
    }

    public function description(): string
    {
        return 'Cash-up variances for a period: shifts closed, total variance (negative = short, positive = over), '
            .'how many shifts were short or over the alert amount, and the closed shifts with expected, counted and '
            .'variance per till.';
    }

    public function requiredAbility(): Ability
    {
        return Ability::CashView;
    }

    protected function report(): ReportKind
    {
        return ReportKind::Shifts;
    }

    protected function tables(): array
    {
        return ['shifts'];
    }
}
