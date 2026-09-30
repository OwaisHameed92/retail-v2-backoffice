<?php

namespace App\Domain\Accounts\Support;

use App\Domain\Accounts\Data\AccountsFilters;
use App\Domain\Reporting\Support\Units;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\Account;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Refund journals posted before the till's 0.1.15 refund fix (PORTAL-CHANGES-0.1.15 item 7): a refund used to be
 * journalled like a sale (sales, VAT and cash went up); from 0.1.15 it debits sales. Old entries are not re-posted.
 *
 * An entry is flagged when it is a refund (its `refType` says refund, or its `refId` is a refund sale) and its income
 * lines are credited overall. That is the wrong direction whatever the till's version, so no version is guessed.
 * "Corrected" figures turn every line of a flagged entry the other way round (debit ↔ credit); the entry still
 * balances.
 */
final class RefundFix
{
    /**
     * Income accounts: the chart's `income` codes (anything in the 4000s counts too, the till's sales accounts).
     *
     * @return list<string>
     */
    public static function incomeCodes(): array
    {
        return Account::query()->where('type', 'income')->distinct()->pluck('code')->filter()->map(fn ($c) => (string) $c)->values()->all();
    }

    /**
     * The ids of the flagged entries dated up to `$filters->to` (in the filtered shop).
     */
    public static function entries(AccountsFilters $filters): Builder
    {
        $company = app(CurrentCompany::class)->require()->getKey();
        $codes = self::incomeCodes();
        $income = "(fl.account_code LIKE '4%'".($codes === [] ? '' : ' OR fl.account_code IN ('.implode(',', array_fill(0, count($codes), '?')).')').')';

        return DB::table('journal_entries as fe')
            ->join('journal_lines as fl', 'fl.journal_entry_id', '=', 'fe.id')
            ->where('fe.company_id', $company)
            ->whereColumn('fl.company_id', 'fe.company_id')
            ->whereNull('fe.deleted_at')
            ->whereNull('fl.deleted_at')
            ->where('fe.date', '<=', $filters->to)
            ->when($filters->shop !== null, fn (Builder $q) => $q->where('fe.branch_id', $filters->shop))
            ->where(fn (Builder $q) => $q
                ->whereRaw("LOWER(COALESCE(fe.ref_type, '')) LIKE ?", ['%refund%'])
                ->orWhereIn('fe.ref_id', DB::table('sales')->select('id')->where('company_id', $company)->where('type', 'refund')))
            ->groupBy('fe.id')
            ->havingRaw('SUM(CASE WHEN '.$income.' THEN ROUND(COALESCE(fl.credit, 0) * 100) - ROUND(COALESCE(fl.debit, 0) * 100) ELSE 0 END) > 0', $codes)
            ->select('fe.id');
    }

    /**
     * How many flagged entries fall in the chosen dates, and the sales they added instead of taking off.
     *
     * @return array{entries: int, sales: string}
     */
    public static function summary(AccountsFilters $filters): array
    {
        $codes = self::incomeCodes();
        $income = "(jl.account_code LIKE '4%'".($codes === [] ? '' : ' OR jl.account_code IN ('.implode(',', array_fill(0, count($codes), '?')).')').')';
        $row = DB::table('journal_lines as jl')
            ->joinSub(self::entries($filters), 'fx', 'fx.id', '=', 'jl.journal_entry_id')
            ->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->whereNull('jl.deleted_at')
            ->where('je.date', '>=', $filters->from)
            ->selectRaw('COUNT(DISTINCT je.id) as n, SUM(CASE WHEN '.$income.' THEN ROUND(COALESCE(jl.credit, 0) * 100) - ROUND(COALESCE(jl.debit, 0) * 100) ELSE 0 END) as sales', $codes)
            ->first();

        return ['entries' => (int) ($row->n ?? 0), 'sales' => Units::decimal(Units::of($row->sales ?? 0), 2)];
    }

    /**
     * The flagged entries among the given ones, whatever their date.
     *
     * @param  list<string>  $ids
     * @return array<string, true>
     */
    public static function among(AccountsFilters $filters, array $ids): array
    {
        $out = [];

        if ($ids === []) {
            return $out;
        }

        foreach (DB::query()->fromSub(self::entries($filters->between('0001-01-01', '9999-12-31')), 'fx')->whereIn('fx.id', $ids)->pluck('fx.id') as $id) {
            $out[(string) $id] = true;
        }

        return $out;
    }
}
