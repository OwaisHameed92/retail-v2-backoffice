<?php

namespace App\Domain\Accounts\Queries;

use App\Domain\Accounts\Data\AccountsFilters;
use App\Domain\Accounts\Support\AccountChart;
use App\Domain\Accounts\Support\Ledger;
use App\Domain\Accounts\Support\RefundFix;
use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Data\VatRateTotals;
use App\Domain\Reporting\Queries\VatReport;
use App\Domain\Reporting\Support\Units;
use App\Domain\Shared\Support\Money;

/**
 * Profit and loss (the chosen dates) and balance sheet (as at the end date) from the tills' journal lines
 * (module 5.5), summed by account code. Costs in the 5000s are cost of sales, other costs overheads (the till's
 * UK chart). The P&L also sets journal sales beside the till sales data (the 4.8 VAT report's net sales), so a
 * difference, e.g. from refunds posted before the 0.1.15 fix, is visible.
 */
final class FinancialStatements
{
    /**
     * @return array<string, mixed>
     */
    public static function profitAndLoss(AccountsFilters $filters): array
    {
        $chart = AccountChart::byCode($filters->shop);
        $groups = ['income' => [], 'costOfSales' => [], 'overheads' => []];
        $sums = ['income' => '0', 'costOfSales' => '0', 'overheads' => '0'];

        foreach (Ledger::balances($filters) as $code => $b) {
            $code = (string) $code;
            $account = AccountChart::entry($chart, $code);
            $group = match (true) {
                $account['type'] === 'income' => 'income',
                $account['type'] === 'expense' && str_starts_with($code, '5') => 'costOfSales',
                $account['type'] === 'expense' => 'overheads',
                default => null,
            };

            if ($group === null) {
                continue;
            }

            $amount = $group === 'income' ? Units::sub($b['pc'], $b['pd']) : Units::sub($b['pd'], $b['pc']);

            if ($amount === '0') {
                continue;
            }

            $groups[$group][] = ['code' => $code, 'name' => $account['name'], 'amount' => Ledger::pounds($amount)];
            $sums[$group] = Units::add($sums[$group], $amount);
        }

        $gross = Units::sub($sums['income'], $sums['costOfSales']);
        $net = Units::sub($gross, $sums['overheads']);
        $journalSales = Ledger::pounds($sums['income']);
        $tillSales = Money::sum(array_map(
            fn (VatRateTotals $r) => $r->net,
            app(VatReport::class)->byRate(ReportScope::tenant($filters->from, $filters->to, $filters->branchIds())),
        ));

        return [
            'income' => $groups['income'],
            'costOfSales' => $groups['costOfSales'],
            'overheads' => $groups['overheads'],
            'totals' => [
                'income' => $journalSales,
                'costOfSales' => Ledger::pounds($sums['costOfSales']),
                'grossProfit' => Ledger::pounds($gross),
                'overheads' => Ledger::pounds($sums['overheads']),
                'netProfit' => Ledger::pounds($net),
            ],
            'salesCheck' => [
                'journals' => $journalSales,
                'salesData' => $tillSales,
                'difference' => Money::sub($journalSales, $tillSales),
                'differs' => ! Money::equals($journalSales, $tillSales),
            ],
            'refundFix' => RefundFix::summary($filters),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function balanceSheet(AccountsFilters $filters): array
    {
        $chart = AccountChart::byCode($filters->shop);
        $groups = ['asset' => [], 'liability' => [], 'equity' => []];
        $sums = ['asset' => '0', 'liability' => '0', 'equity' => '0', 'profit' => '0'];

        foreach (Ledger::balances($filters) as $code => $b) {
            $code = (string) $code;
            $type = AccountChart::entry($chart, $code)['type'];
            $debitSide = Units::sub($b['bd'], $b['bc']);

            if (in_array($type, ['income', 'expense'], true)) {
                $sums['profit'] = Units::sub($sums['profit'], $debitSide);

                continue;
            }

            $amount = $type === 'asset' ? $debitSide : Units::neg($debitSide);

            if ($amount === '0') {
                continue;
            }

            $groups[$type][] = ['code' => $code, 'name' => AccountChart::entry($chart, $code)['name'], 'amount' => Ledger::pounds($amount)];
            $sums[$type] = Units::add($sums[$type], $amount);
        }

        $claims = Units::add($sums['liability'], $sums['equity'], $sums['profit']);

        return [
            'assets' => $groups['asset'],
            'liabilities' => $groups['liability'],
            'equity' => $groups['equity'],
            'totals' => [
                'assets' => Ledger::pounds($sums['asset']),
                'liabilities' => Ledger::pounds($sums['liability']),
                'equity' => Ledger::pounds($sums['equity']),
                'profit' => Ledger::pounds($sums['profit']),
                'netAssets' => Ledger::pounds(Units::sub($sums['asset'], $sums['liability'])),
                'capital' => Ledger::pounds(Units::add($sums['equity'], $sums['profit'])),
                'difference' => Ledger::pounds(Units::sub($sums['asset'], $claims)),
            ],
            'balanced' => $sums['asset'] === $claims,
            'refundFix' => RefundFix::summary($filters),
        ];
    }
}
