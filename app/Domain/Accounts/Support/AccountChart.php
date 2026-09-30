<?php

namespace App\Domain\Accounts\Support;

use App\Domain\TillData\Models\Account;

/**
 * The company-wide chart of accounts (module 5.5). Every shop's till makes its own `Account` rows with the same
 * `code` (PORTAL-CHANGES-0.1.15 item 2), so the chart is grouped by code: the newest row gives the name, type,
 * parent and VAT box; "shops" counts the tills' copies. A code seen only on journal lines gets its type from its
 * first digit (the till's UK chart: 1 assets, 2 liabilities, 3 capital, 4 income, 5–9 costs).
 *
 * @phpstan-type ChartEntry array{code: string, name: string, type: string, parentCode: string|null, vatBox: int|null, isSystem: bool, isActive: bool, isDebitBalance: bool, shops: int}
 */
final class AccountChart
{
    public const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];

    /**
     * @return array<string, ChartEntry>
     */
    public static function byCode(?string $shop = null): array
    {
        $rows = Account::query()
            ->when($shop !== null, fn ($q) => $q->where(fn ($w) => $w->where('origin_branch_id', $shop)->orWhereNull('origin_branch_id')))
            ->orderBy('code')->orderByDesc('updated_at')
            ->get(['id', 'code', 'name', 'type', 'parent_code', 'vat_box', 'is_system', 'is_active', 'is_debit_balance', 'origin_branch_id', 'updated_at']);

        $chart = [];

        foreach ($rows as $a) {
            $code = (string) $a->code;

            if ($code === '') {
                continue;
            }

            if (! isset($chart[$code])) {
                $chart[$code] = [
                    'code' => $code,
                    'name' => (string) $a->name !== '' ? (string) $a->name : 'Account '.$code,
                    'type' => $a->type->value ?? self::guessType($code),
                    'parentCode' => $a->parent_code !== null && $a->parent_code !== '' ? (string) $a->parent_code : null,
                    'vatBox' => $a->vat_box,
                    'isSystem' => (bool) $a->is_system,
                    'isActive' => (bool) $a->is_active,
                    'isDebitBalance' => $a->is_debit_balance ?? in_array(self::guessType($code), ['asset', 'expense'], true),
                    'shops' => 0,
                ];
            }

            $chart[$code]['shops']++;
            $chart[$code]['isActive'] = $chart[$code]['isActive'] || (bool) $a->is_active;
        }

        ksort($chart, SORT_STRING);

        return $chart;
    }

    /**
     * One code's entry, or a stand-in for a code the chart does not have.
     *
     * @param  array<string, ChartEntry>  $chart
     * @return ChartEntry
     */
    public static function entry(array $chart, string $code): array
    {
        return $chart[$code] ?? [
            'code' => $code, 'name' => 'Account '.$code, 'type' => self::guessType($code), 'parentCode' => null, 'vatBox' => null,
            'isSystem' => false, 'isActive' => true, 'isDebitBalance' => in_array(self::guessType($code), ['asset', 'expense'], true), 'shops' => 0,
        ];
    }

    public static function guessType(string $code): string
    {
        return match ($code[0] ?? '') {
            '1' => 'asset',
            '2' => 'liability',
            '3' => 'equity',
            '4' => 'income',
            default => 'expense',
        };
    }
}
