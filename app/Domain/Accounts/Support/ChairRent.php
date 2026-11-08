<?php

namespace App\Domain\Accounts\Support;

use App\Domain\Accounts\Data\AccountsFilters;
use App\Domain\Reporting\Support\Units;
use App\Domain\TillData\Models\JournalLine;
use Illuminate\Database\Eloquent\Builder;

/**
 * Chair rent a salon charges its self-employed chair renters (salon pack 2026-11-08, W2): ledger account 4200 "Chair
 * rent from renters", which the till's chart puts in VAT box 6. It is not a till sale, so the VAT return helper adds
 * it from the tills' journals (VatReturnHelper):
 *
 * - box 6: credits less debits on 4200 for journal entries dated in the quarter (a cancelled statement's reversal
 *   takes its rent back off);
 * - box 1: the VAT those same entries post to the account(s) the till's chart marks as VAT box 1 (a renter
 *   agreement with VAT on the rent, `SalonRenterStatement.rentVat`). Without such a line it adds nothing.
 *
 * A business with no 4200 line gets count 0 and the helper shows nothing new. Call inside the company scope.
 */
final class ChairRent
{
    public const ACCOUNT = '4200';

    /**
     * @return array{count: int, net: string, vat: string}
     */
    public static function for(AccountsFilters $filters): array
    {
        $rent = self::inQuarter($filters)->where('journal_lines.account_code', self::ACCOUNT)
            ->toBase()->selectRaw('COUNT(DISTINCT je.id) as n, SUM(ROUND(COALESCE(journal_lines.credit, 0) * 100) - ROUND(COALESCE(journal_lines.debit, 0) * 100)) as net')
            ->first();
        $count = (int) ($rent->n ?? 0);

        if ($count === 0) {
            return ['count' => 0, 'net' => '0.00', 'vat' => '0.00'];
        }

        $vatCodes = array_keys(array_filter(AccountChart::byCode($filters->shop), fn (array $a) => $a['vatBox'] === 1));
        $vat = $vatCodes === [] ? null : self::inQuarter($filters)
            ->whereIn('journal_lines.account_code', $vatCodes)
            ->whereIn('je.id', fn ($q) => $q->select('journal_entry_id')->from('journal_lines')->where('account_code', self::ACCOUNT)->whereNull('deleted_at'))
            ->toBase()->selectRaw('SUM(ROUND(COALESCE(journal_lines.credit, 0) * 100) - ROUND(COALESCE(journal_lines.debit, 0) * 100)) as vat')
            ->value('vat');

        return ['count' => $count, 'net' => Units::decimal(Units::of($rent->net ?? null), 2), 'vat' => Units::decimal(Units::of($vat), 2)];
    }

    /** @return Builder<JournalLine> */
    private static function inQuarter(AccountsFilters $filters): Builder
    {
        return Ledger::lines($filters)->whereNull('journal_lines.deleted_at')
            ->where('je.date', '>=', $filters->from)->where('je.date', '<=', $filters->to);
    }
}
