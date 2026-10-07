<?php

namespace App\Domain\Accounts\Export;

use App\Domain\Accounts\Data\AccountsFilters;
use App\Domain\Accounts\Support\AccountChart;
use App\Domain\Accounts\Support\RefundFix;
use App\Domain\Shared\Country\Country;
use App\Domain\TillData\Models\JournalLine;
use App\Domain\TillData\Models\VatRate;

/**
 * Props for the export screens (gap #8): the preview (first journals, totals, unmapped codes) and the mapping
 * screen (every account code the business uses or the defaults know, every VAT code, with default and own values).
 * Runs in the company scope.
 */
final class ExportPages
{
    public const PREVIEW_JOURNALS = 20;

    /**
     * @return array<string, mixed>
     */
    public static function preview(AccountsFilters $filters, ExportTarget $target, string $grouping, string $business): array
    {
        $summary = JournalSummary::build($filters, ExportMappings::for($target), $grouping, $business);
        $journals = array_slice($summary['journals'], 0, self::PREVIEW_JOURNALS);
        $format = $target->format();
        $sample = [];

        foreach (array_slice($journals, 0, 2) as $journal) {
            foreach ($format->rows($journal) as $row) {
                $sample[] = $row;
            }
        }

        return [
            'target' => $target->value,
            'grouping' => $grouping,
            'targets' => self::targets(),
            'importHelp' => $target->importHelp(),
            'journals' => $journals,
            'more' => max(0, count($summary['journals']) - self::PREVIEW_JOURNALS),
            'totals' => $summary['totals'],
            'unmapped' => $summary['unmapped'],
            'sample' => ['header' => $format->header(), 'rows' => array_slice($sample, 0, 8)],
            'refundFix' => RefundFix::summary($filters),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function mappings(ExportTarget $target): array
    {
        $mappings = ExportMappings::for($target);
        $defaults = ExportDefaults::accounts($target);
        $chart = AccountChart::byCode();
        $codes = array_unique([
            ...array_keys($chart),
            ...JournalLine::query()->distinct()->limit(500)->pluck('account_code')->filter()->map(fn ($c) => (string) $c)->all(),
            ...array_map('strval', array_keys($defaults)),
        ]);
        sort($codes, SORT_STRING);

        $vatNames = ExportDefaults::vatCodes();

        foreach (VatRate::query()->orderBy('code')->get(['code', 'name', 'percentage']) as $rate) {
            $code = (string) $rate->code;

            if ($code !== '' && ! isset($vatNames[$code])) {
                $vatNames[$code] = trim($rate->name.' '.rtrim(rtrim((string) $rate->percentage, '0'), '.').'%');
            }
        }

        $vatDefaults = ExportDefaults::vat($target);
        $vatRows = [];

        foreach ([...$vatNames, ExportDefaults::NO_VAT => Country::tax('No VAT rate on the line')] as $code => $name) {
            $vatRows[] = [
                'code' => (string) $code,
                'name' => $name,
                'defaultSales' => $vatDefaults[$code][0] ?? $vatDefaults[ExportDefaults::NO_VAT][0],
                'defaultPurchases' => $vatDefaults[$code][1] ?? $vatDefaults[ExportDefaults::NO_VAT][1],
                'sales' => $mappings->ownVat[$code][0] ?? '',
                'purchases' => $mappings->ownVat[$code][1] ?? '',
            ];
        }

        return [
            'target' => $target->value,
            'targets' => self::targets(),
            'accounts' => array_map(fn (string $code) => [
                'code' => $code,
                'name' => AccountChart::entry($chart, $code)['name'],
                'type' => AccountChart::entry($chart, $code)['type'],
                'default' => $defaults[$code] ?? null,
                'theirs' => $mappings->ownAccounts[$code] ?? '',
            ], $codes),
            'vat' => $vatRows,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function targets(): array
    {
        return array_map(fn (ExportTarget $t) => ['value' => $t->value, 'label' => $t->label()], ExportTarget::cases());
    }
}
