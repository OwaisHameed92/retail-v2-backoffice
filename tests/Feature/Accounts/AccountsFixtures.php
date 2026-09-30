<?php

namespace Tests\Feature\Accounts;

use Illuminate\Support\Facades\DB;
use Tests\Feature\TillData\TillFixtures;

/**
 * Module 5.5 test rows, written straight to the till tables as the push path stores them.
 *
 * The standard book (Kirkgate, September 2026): Leeds and Bradford each have their own copy of every account (same
 * codes). Entries: an August sale (Leeds), a Leeds and a Bradford sale, an old refund journalled like a sale (Leeds,
 * before the 0.1.15 fix), a correct refund (Leeds) and a Leeds stock purchase.
 */
final class AccountsFixtures
{
    public const ACCOUNTS = [
        '1000' => ['Cash in tills', 'asset', null],
        '2200' => ['VAT output', 'liability', 1],
        '2240' => ['Customer deposits held', 'liability', null],
        '4000' => ['Sales standard rated', 'income', 6],
        '5000' => ['Purchases', 'expense', 7],
    ];

    public const E_AUG = '01K5T0Q8C4000000000000JAUG';

    public const E_LEEDS = '01K5T0Q8C4000000000000JLD1';

    public const E_BRAD = '01K5T0Q8C4000000000000JBR1';

    public const E_OLD_REFUND = '01K5T0Q8C4000000000000JOLD';

    public const E_NEW_REFUND = '01K5T0Q8C4000000000000JNEW';

    public const E_PURCHASE = '01K5T0Q8C4000000000000JPUR';

    public static function id(string $prefix, int|string $n): string
    {
        return '01K5T0Q8C4'.$prefix.str_pad((string) $n, 16 - strlen($prefix), '0', STR_PAD_LEFT);
    }

    public static function chart(string $companyId, string $branchId, string $tag): void
    {
        foreach (self::ACCOUNTS as $code => [$name, $type, $box]) {
            DB::table('ledger_accounts')->insert([
                'id' => self::id('AC'.$tag, $code), 'company_id' => $companyId, 'code' => (string) $code, 'name' => $name, 'type' => $type,
                'vat_box' => $box, 'is_system' => true, 'is_active' => true, 'is_debit_balance' => in_array($type, ['asset', 'expense'], true),
                'origin_branch_id' => $branchId, 'updated_at' => '2026-09-01 00:00:00',
            ]);
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $lines  [code, debit, credit]
     */
    public static function entry(string $companyId, string $branchId, string $id, string $date, string $refType, array $lines, string $refId = ''): void
    {
        $debits = '0.00';
        $credits = '0.00';

        foreach ($lines as $i => [$code, $debit, $credit]) {
            DB::table('journal_lines')->insert([
                'id' => substr($id, 0, 20).substr($id, -4).str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'company_id' => $companyId, 'branch_id' => $branchId,
                'journal_entry_id' => $id, 'account_id' => 'ACCOUNT'.$code, 'account_code' => $code, 'debit' => $debit, 'credit' => $credit, 'memo' => '',
            ]);
            $debits = bcadd($debits, $debit, 2);
            $credits = bcadd($credits, $credit, 2);
        }

        DB::table('journal_entries')->insert([
            'id' => $id, 'company_id' => $companyId, 'branch_id' => $branchId, 'register_id' => null, 'date' => $date, 'ref_type' => $refType,
            'ref_id' => $refId, 'memo' => $refType.' '.$date, 'total_debits' => $debits, 'total_credits' => $credits, 'is_reversed' => false,
            'posted_at' => $date.' 12:00:00',
        ]);
    }

    /** The standard book described above. */
    public static function book(string $companyId): void
    {
        self::chart($companyId, TillFixtures::LEEDS, 'L');
        self::chart($companyId, TillFixtures::BRADFORD, 'B');
        self::entry($companyId, TillFixtures::LEEDS, self::E_AUG, '2026-08-20', 'Sale', [['1000', '24.00', '0.00'], ['4000', '0.00', '20.00'], ['2200', '0.00', '4.00']]);
        self::entry($companyId, TillFixtures::LEEDS, self::E_LEEDS, '2026-09-10', 'Sale', [['1000', '120.00', '0.00'], ['4000', '0.00', '100.00'], ['2200', '0.00', '20.00']]);
        self::entry($companyId, TillFixtures::BRADFORD, self::E_BRAD, '2026-09-11', 'Sale', [['1000', '60.00', '0.00'], ['4000', '0.00', '50.00'], ['2200', '0.00', '10.00']]);
        self::entry($companyId, TillFixtures::LEEDS, self::E_OLD_REFUND, '2026-09-12', 'Refund', [['1000', '12.00', '0.00'], ['4000', '0.00', '10.00'], ['2200', '0.00', '2.00']]);
        self::entry($companyId, TillFixtures::LEEDS, self::E_NEW_REFUND, '2026-09-13', 'Refund', [['4000', '5.00', '0.00'], ['2200', '1.00', '0.00'], ['1000', '0.00', '6.00']]);
        self::entry($companyId, TillFixtures::LEEDS, self::E_PURCHASE, '2026-09-14', 'Expense', [['5000', '30.00', '0.00'], ['1000', '0.00', '30.00']]);
    }

    /** Till sales data (`rpt_vat_daily`, the 4.8 VAT report's table) matching the September sales and refunds. */
    public static function salesData(string $companyId): void
    {
        foreach ([
            [TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-10', '100.00', '20.00'],
            [TillFixtures::BRADFORD, TillFixtures::BRADFORD_TILL, '2026-09-11', '50.00', '10.00'],
            [TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-12', '-10.00', '-2.00'],
            [TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-13', '-5.00', '-1.00'],
        ] as [$branch, $till, $day, $net, $vat]) {
            DB::table('rpt_vat_daily')->insert([
                'company_id' => $companyId, 'branch_id' => $branch, 'trading_day' => $day, 'register_id' => $till, 'vat_rate_id' => 'VATSTANDARD',
                'percentage' => '20.00', 'code' => 'S', 'net' => $net, 'vat' => $vat, 'gross' => bcadd($net, $vat, 2), 'rebuilt_at' => '2026-09-23 00:00:00',
            ]);
        }
    }
}
