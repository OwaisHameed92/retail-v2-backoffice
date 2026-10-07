<?php

namespace App\Domain\Accounts\Export;

use App\Domain\Shared\Country\Country;

/**
 * Suggested mappings for each package (gap #8), used for any code the business has not mapped itself.
 *
 * Accounts: the till's UK chart codes (1000 cash in tills, 2200 VAT output, 4000–4040 sales by VAT treatment, 5000
 * purchases, 6100 cash over/short, 6900 loyalty points expired, 9999 suspense) → each package's standard UK chart.
 * Codes with no sensible standard home (2220 DRS deposits, 2230 loyalty points, 2240 order deposits, 2250 charity)
 * stay unmapped: the export keeps our code and the preview asks for a mapping.
 *
 * VAT: the till's journal already carries VAT on its own line (2200), so by default no package is asked to work out
 * VAT again: Xero, QuickBooks and Sage Accounting lines are "No VAT". Sage 50 lines carry the real T codes with a
 * tax amount of 0.00 (the usual coding of an EPOS journal in Sage 50), so net sales land in the VAT return's boxes.
 */
final class ExportDefaults
{
    /**
     * Pakistan plan P10 (owner 2026-10-07): the default tax codes off GB, the same neutral labels for every package
     * (the UK packages' VAT codes mean nothing to a Pakistani accountant). A business still maps its own.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const PK_VAT = [
        'S' => ['GST 18% (sales)', 'GST 18% (purchases)'],
        'R' => ['GST reduced rate (sales)', 'GST reduced rate (purchases)'],
        'Z' => ['Zero rated', 'Zero rated'],
        'E' => ['GST exempt', 'GST exempt'],
        'O' => ['No GST', 'No GST'],
        '-' => ['No GST', 'No GST'],
    ];

    /** The till's VAT code names off GB (P10): Pakistan's standard GST rate is 18%. */
    private const PK_VAT_CODES = ['S' => 'Standard 18%', 'R' => 'Reduced rate', 'Z' => 'Zero rated', 'E' => 'Exempt', 'O' => 'Outside the scope of GST'];

    /** The till's VAT codes (VatRate.code) shown on the mapping screen even before a till sends them. */
    public const VAT_CODES = ['S' => 'Standard 20%', 'R' => 'Reduced 5%', 'Z' => 'Zero rated', 'E' => 'Exempt', 'O' => 'Outside the scope of VAT'];

    /** The mapping row (kind vat) for lines with no VAT rate. */
    public const NO_VAT = '-';

    /** @var array<string, array<int|string, string>> numeric codes become int keys in PHP */
    private const ACCOUNTS = [
        'xero' => ['1000' => '090', '2200' => '820', '4000' => '200', '4010' => '200', '4020' => '200', '4030' => '200', '4040' => '200', '5000' => '310', '6100' => '860', '6900' => '260', '9999' => '850'],
        'quickbooks' => ['1000' => 'Undeposited Funds', '2200' => 'VAT Control', '4000' => 'Sales', '4010' => 'Sales', '4020' => 'Sales', '4030' => 'Sales', '4040' => 'Sales', '5000' => 'Cost of sales', '6100' => 'Other Expenses', '6900' => 'Other Income', '9999' => 'Suspense'],
        'sage50' => ['1000' => '1230', '2200' => '2200', '4000' => '4000', '4010' => '4000', '4020' => '4000', '4030' => '4000', '4040' => '4000', '5000' => '5000', '6100' => '8200', '6900' => '4900', '9999' => '9998'],
        'sageAccounting' => ['1000' => '1210', '2200' => '2200', '4000' => '4000', '4010' => '4000', '4020' => '4000', '4030' => '4000', '4040' => '4000', '5000' => '5000', '6100' => '8200', '6900' => '4900', '9999' => '9998'],
    ];

    /** @var array<string, array<string, array{0: string, 1: string}>> our VAT code => [sales, purchases] */
    private const VAT = [
        'xero' => ['S' => ['No VAT', 'No VAT'], 'R' => ['No VAT', 'No VAT'], 'Z' => ['No VAT', 'No VAT'], 'E' => ['No VAT', 'No VAT'], 'O' => ['No VAT', 'No VAT'], '-' => ['No VAT', 'No VAT']],
        'quickbooks' => ['S' => ['No VAT', 'No VAT'], 'R' => ['No VAT', 'No VAT'], 'Z' => ['No VAT', 'No VAT'], 'E' => ['No VAT', 'No VAT'], 'O' => ['No VAT', 'No VAT'], '-' => ['No VAT', 'No VAT']],
        'sage50' => ['S' => ['T1', 'T1'], 'R' => ['T5', 'T5'], 'Z' => ['T0', 'T0'], 'E' => ['T2', 'T2'], 'O' => ['T9', 'T9'], '-' => ['T9', 'T9']],
        'sageAccounting' => ['S' => ['No VAT', 'No VAT'], 'R' => ['No VAT', 'No VAT'], 'Z' => ['No VAT', 'No VAT'], 'E' => ['No VAT', 'No VAT'], 'O' => ['No VAT', 'No VAT'], '-' => ['No VAT', 'No VAT']],
    ];

    /** @return array<int|string, string> our code => their code */
    public static function accounts(ExportTarget $target): array
    {
        return self::ACCOUNTS[$target->value];
    }

    /** @return array<string, array{0: string, 1: string}> our VAT code (or NO_VAT) => [sales, purchases] */
    public static function vat(ExportTarget $target): array
    {
        return app(Country::class)->is(Country::DEFAULT) ? self::VAT[$target->value] : self::PK_VAT;
    }

    /**
     * The names of the till's VAT codes on the mapping screen: VAT_CODES on GB, the Pakistani ones elsewhere (P10).
     *
     * @return array<string, string>
     */
    public static function vatCodes(): array
    {
        return app(Country::class)->is(Country::DEFAULT) ? self::VAT_CODES : self::PK_VAT_CODES;
    }
}
