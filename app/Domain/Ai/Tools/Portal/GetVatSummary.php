<?php

namespace App\Domain\Ai\Tools\Portal;

use App\Domain\Reporting\Reports\ReportKind;
use App\Domain\Shared\Country\Country;

/**
 * Read: the VAT report for a period (module 4.8): net, VAT and gross per rate, ready for the VAT return.
 */
final class GetVatSummary extends GetReportFigures
{
    public function name(): string
    {
        return 'get_vat_summary';
    }

    public function description(): string
    {
        return Country::tax('VAT on sales for a period: net, VAT and gross per VAT rate and in total, with the change against the '
            .'previous period. Use it for VAT return questions (output VAT on sales only).');
    }

    protected function report(): ReportKind
    {
        return ReportKind::Vat;
    }

    protected function tables(): array
    {
        return ['rates'];
    }
}
