<?php

namespace App\Domain\Accounts\Queries;

use App\Domain\Accounts\Data\AccountsFilters;
use App\Domain\Accounts\Support\AccountChart;
use App\Domain\Accounts\Support\Ledger;
use App\Domain\Tenancy\Models\Branch;

/**
 * The chart of accounts for the whole company (module 5.5): one row per account code (every shop's till has its
 * own copy with the same code), with the period's movement and the balance as at the end date, on the account's
 * natural side (debit for assets and costs, credit for the rest). Read only: the tills make and post to accounts.
 */
final class ChartOfAccounts
{
    /**
     * @return array<string, mixed>
     */
    public static function for(AccountsFilters $filters): array
    {
        $chart = AccountChart::byCode($filters->shop);
        $balances = Ledger::balances($filters);

        foreach (array_keys($balances) as $code) {
            $chart[$code] ??= AccountChart::entry($chart, (string) $code);
        }

        ksort($chart, SORT_STRING);
        $shops = $filters->shop !== null ? 1 : Branch::query()->count();

        $rows = array_map(function (array $a) use ($balances) {
            $b = $balances[$a['code']] ?? ['pd' => '0', 'pc' => '0', 'bd' => '0', 'bc' => '0'];
            $sign = $a['isDebitBalance'] ? 1 : -1;
            $movement = Ledger::net($b['pd'], $b['pc']);
            $balance = Ledger::net($b['bd'], $b['bc']);

            return [
                ...$a,
                'movement' => Ledger::pounds($sign === 1 ? $movement : bcsub('0', $movement, 0)),
                'balance' => Ledger::pounds($sign === 1 ? $balance : bcsub('0', $balance, 0)),
                'hasLines' => isset($balances[$a['code']]),
            ];
        }, array_values($chart));

        return [
            'accounts' => $rows,
            'types' => array_map(fn (string $t) => ['type' => $t, 'count' => count(array_filter($rows, fn (array $r) => $r['type'] === $t))], AccountChart::TYPES),
            'shopCount' => $shops,
        ];
    }
}
