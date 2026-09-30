<?php

namespace App\Domain\Accounts\Queries;

use App\Domain\Accounts\Data\AccountsFilters;
use App\Domain\Accounts\Support\AccountChart;
use App\Domain\Accounts\Support\Ledger;
use App\Domain\Accounts\Support\RefundFix;
use App\Domain\Reporting\Support\Units;

/**
 * Trial balance (module 5.5) from the tills' journal lines, one row per account code (all shops' copies together):
 * the period's debits and credits, and the balance as at the end date (every entry up to it) in the debit or
 * credit column. Both pairs of totals agree when the ledger balances; `balanced` says so.
 */
final class TrialBalance
{
    /**
     * @return array<string, mixed>
     */
    public static function for(AccountsFilters $filters): array
    {
        $chart = AccountChart::byCode($filters->shop);
        $rows = [];
        $totals = ['pd' => '0', 'pc' => '0', 'dr' => '0', 'cr' => '0'];

        foreach (Ledger::balances($filters) as $code => $b) {
            $code = (string) $code;
            $account = AccountChart::entry($chart, $code);
            $net = Ledger::net($b['bd'], $b['bc']);
            $dr = bccomp($net, '0') > 0 ? $net : '0';
            $cr = bccomp($net, '0') < 0 ? Units::neg($net) : '0';

            if ($b['pd'] === '0' && $b['pc'] === '0' && $dr === '0' && $cr === '0') {
                continue;
            }

            $rows[] = [
                'code' => $code,
                'name' => $account['name'],
                'type' => $account['type'],
                'periodDebit' => Ledger::pounds($b['pd']),
                'periodCredit' => Ledger::pounds($b['pc']),
                'debit' => $dr === '0' ? null : Ledger::pounds($dr),
                'credit' => $cr === '0' ? null : Ledger::pounds($cr),
            ];

            $totals = [
                'pd' => Units::add($totals['pd'], $b['pd']), 'pc' => Units::add($totals['pc'], $b['pc']),
                'dr' => Units::add($totals['dr'], $dr), 'cr' => Units::add($totals['cr'], $cr),
            ];
        }

        return [
            'rows' => $rows,
            'totals' => [
                'periodDebit' => Ledger::pounds($totals['pd']),
                'periodCredit' => Ledger::pounds($totals['pc']),
                'debit' => Ledger::pounds($totals['dr']),
                'credit' => Ledger::pounds($totals['cr']),
                'difference' => Ledger::pounds(Units::sub($totals['dr'], $totals['cr'])),
            ],
            'balanced' => $totals['dr'] === $totals['cr'] && $totals['pd'] === $totals['pc'],
            'refundFix' => RefundFix::summary($filters),
        ];
    }
}
