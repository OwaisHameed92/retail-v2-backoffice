<?php

namespace App\Domain\Accounts\Support;

use App\Domain\Accounts\Data\AccountsFilters;
use App\Domain\Reporting\Support\Units;
use App\Domain\TillData\Models\JournalLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Sums of the tills' journal lines per account code (module 5.5), in whole pence so SQLite never drifts. Lines and
 * entries of the current company (global scope), not deleted, in the filtered shop. Reversed entries stay in: their
 * reversal is posted too, so the pair nets to nothing. With `fix`, old refund entries are turned round (RefundFix).
 */
final class Ledger
{
    /**
     * Per code: debits and credits of the chosen dates (`pd`/`pc`) and everything up to `to` (`bd`/`bc`), in pence.
     *
     * @return array<string, array{pd: string, pc: string, bd: string, bc: string}>
     */
    public static function balances(AccountsFilters $filters): array
    {
        [$debit, $credit] = self::amounts($filters);
        $grammar = DB::connection()->getQueryGrammar();
        $from = DB::getPdo()->quote($filters->from);
        $inPeriod = 'je.date >= '.$from;

        $rows = self::lines($filters, $filters->fix)
            ->where('je.date', '<=', $filters->to)
            ->groupBy('journal_lines.account_code')
            ->select([
                DB::raw($grammar->wrap('journal_lines.account_code').' as code'),
                DB::raw('SUM(CASE WHEN '.$inPeriod.' THEN '.$debit.' ELSE 0 END) as pd'),
                DB::raw('SUM(CASE WHEN '.$inPeriod.' THEN '.$credit.' ELSE 0 END) as pc'),
                DB::raw('SUM('.$debit.') as bd'),
                DB::raw('SUM('.$credit.') as bc'),
            ])
            ->toBase()->get();

        $out = [];

        foreach ($rows as $row) {
            $code = (string) ($row->code ?? '');
            $out[$code !== '' ? $code : '—'] = ['pd' => Units::of($row->pd), 'pc' => Units::of($row->pc), 'bd' => Units::of($row->bd), 'bc' => Units::of($row->bc)];
        }

        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * Journal lines joined to their entries, in the filtered shop; with `$fix`, left-joined to the flagged entries
     * (`fx.id` set = an old refund entry).
     *
     * @return Builder<JournalLine>
     */
    public static function lines(AccountsFilters $filters, bool $fix = false): Builder
    {
        return JournalLine::query()
            ->join('journal_entries as je', 'je.id', '=', 'journal_lines.journal_entry_id')
            ->whereColumn('je.company_id', 'journal_lines.company_id')
            ->whereNull('je.deleted_at')
            ->when($filters->shop !== null, fn (Builder $q) => $q->where('je.branch_id', $filters->shop))
            ->when($fix, fn (Builder $q) => $q->leftJoinSub(RefundFix::entries($filters), 'fx', 'fx.id', '=', 'je.id'));
    }

    /**
     * SQL for a line's debit and credit in pence, swapped on a flagged entry when fixing.
     *
     * @return array{0: string, 1: string}
     */
    private static function amounts(AccountsFilters $filters): array
    {
        $d = 'ROUND(COALESCE(journal_lines.debit, 0) * 100)';
        $c = 'ROUND(COALESCE(journal_lines.credit, 0) * 100)';

        if (! $filters->fix) {
            return [$d, $c];
        }

        return ["(CASE WHEN fx.id IS NULL THEN {$d} ELSE {$c} END)", "(CASE WHEN fx.id IS NULL THEN {$c} ELSE {$d} END)"];
    }

    /** Pence (a units string) to pounds, 2 dp. */
    public static function pounds(string $pence): string
    {
        return Units::decimal($pence, 2);
    }

    /** Debit minus credit, in pence. */
    public static function net(string $debit, string $credit): string
    {
        return Units::sub($debit, $credit);
    }
}
