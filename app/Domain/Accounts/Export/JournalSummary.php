<?php

namespace App\Domain\Accounts\Export;

use App\Domain\Accounts\Data\AccountsFilters;
use App\Domain\Accounts\Support\AccountChart;
use App\Domain\Accounts\Support\Ledger;
use App\Domain\Reporting\Support\Units;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Summary journals for an accounting package (gap #8), from the tills' journal lines (module 5.5 data, read through
 * Ledger, so the shop filter, deleted rows and the refund fix behave as on the Accounts screens).
 *
 * One journal per shop per day (`daily`) or per shop for the whole period (`period`). Inside a journal, lines are
 * netted per account code and VAT code (debits minus credits, in pence, never floats); a line that nets to zero is
 * left out. Every journal balances because every till entry does. Each line carries our code, their mapped code
 * (our code itself when unmapped, flagged) and their tax code.
 *
 * @phpstan-type JournalLineRow array{ourCode: string, name: string, theirCode: string, mapped: bool, vatCode: string|null, taxCode: string, debit: string, credit: string}
 * @phpstan-type Journal array{ref: string, date: string, from: string, to: string, shop: string, narration: string, lines: list<JournalLineRow>, debits: string, credits: string}
 */
final class JournalSummary
{
    public const GROUPINGS = ['daily', 'period'];

    /**
     * @return array{journals: list<Journal>, unmapped: list<array{code: string, name: string}>, totals: array{debits: string, credits: string, journals: int, lines: int}}
     */
    public static function build(AccountsFilters $filters, ExportMappings $mappings, string $grouping, string $business): array
    {
        $daily = $grouping !== 'period';
        [$debit, $credit] = Ledger::amounts($filters);
        $grammar = DB::connection()->getQueryGrammar();

        $rows = Ledger::lines($filters, $filters->fix)
            ->leftJoin('vat_rates as vr', fn (JoinClause $j) => $j->on('vr.id', '=', 'journal_lines.vat_rate_id')->whereColumn('vr.company_id', 'journal_lines.company_id'))
            ->where('je.date', '>=', $filters->from)->where('je.date', '<=', $filters->to)
            ->when($daily, fn ($q) => $q->groupBy('je.date'))
            ->groupBy('je.branch_id', 'journal_lines.account_code', 'vr.code')
            ->select(array_values(array_filter([
                $daily ? DB::raw('je.date as day') : null,
                DB::raw('je.branch_id as branch'),
                DB::raw($grammar->wrap('journal_lines.account_code').' as code'),
                DB::raw('vr.code as vat'),
                DB::raw('SUM('.$debit.') as dr'),
                DB::raw('SUM('.$credit.') as cr'),
            ])))
            ->toBase()->get();

        $chart = AccountChart::byCode($filters->shop);
        $shops = Branch::query()->get(['id', 'name', 'code'])->keyBy('id');
        $journals = [];
        $unmapped = [];

        foreach ($rows as $row) {
            $net = Units::sub(Units::of($row->dr), Units::of($row->cr));

            if (bccomp($net, '0', 0) === 0) {
                continue;
            }

            $day = $daily ? substr((string) $row->day, 0, 10) : $filters->to;
            $branch = (string) ($row->branch ?? '');
            $key = $day.'|'.$branch;
            $code = (string) ($row->code ?? '') !== '' ? (string) $row->code : '—';
            $vat = $row->vat !== null && $row->vat !== '' ? (string) $row->vat : null;
            $entry = AccountChart::entry($chart, $code);
            $their = $mappings->account($code);

            if ($their === null) {
                $unmapped[$code] = ['code' => $code, 'name' => $entry['name']];
            }

            $journals[$key] ??= self::journal($daily ? $day : $filters->from, $day, $branch, $shops->get($branch), $business);
            $journals[$key]['lines'][] = [
                'ourCode' => $code,
                'name' => $entry['name'],
                'theirCode' => $their ?? $code,
                'mapped' => $their !== null,
                'vatCode' => $vat,
                'taxCode' => $mappings->tax($vat, in_array($entry['type'], ['income', 'liability', 'equity'], true)),
                'debit' => bccomp($net, '0', 0) > 0 ? Ledger::pounds($net) : '0.00',
                'credit' => bccomp($net, '0', 0) < 0 ? Ledger::pounds(Units::neg($net)) : '0.00',
            ];
        }

        $journals = array_values($journals);
        usort($journals, fn (array $a, array $b) => [$a['date'], $a['shop']] <=> [$b['date'], $b['shop']]);
        $totals = ['debits' => '0.00', 'credits' => '0.00', 'journals' => count($journals), 'lines' => 0];

        foreach ($journals as &$journal) {
            usort($journal['lines'], fn (array $a, array $b) => [$a['ourCode'], $a['vatCode'] ?? ''] <=> [$b['ourCode'], $b['vatCode'] ?? '']);
            $journal['debits'] = array_reduce($journal['lines'], fn (string $s, array $l) => bcadd($s, $l['debit'], 2), '0.00');
            $journal['credits'] = array_reduce($journal['lines'], fn (string $s, array $l) => bcadd($s, $l['credit'], 2), '0.00');
            $totals['debits'] = bcadd($totals['debits'], $journal['debits'], 2);
            $totals['credits'] = bcadd($totals['credits'], $journal['credits'], 2);
            $totals['lines'] += count($journal['lines']);
        }
        unset($journal);

        ksort($unmapped, SORT_STRING);

        return ['journals' => $journals, 'unmapped' => array_values($unmapped), 'totals' => $totals];
    }

    /**
     * @return Journal
     */
    private static function journal(string $from, string $to, string $branchId, ?Branch $branch, string $business): array
    {
        $shop = $branch === null ? 'Unknown shop' : (string) $branch->name;
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($branch->code ?? '')) ?: substr($branchId, -4));
        $f = CarbonImmutable::parse($from);
        $t = CarbonImmutable::parse($to);
        $same = $from === $to;

        return [
            'ref' => 'SSPOS-'.$f->format('Ymd').($same ? '' : '-'.$t->format('Ymd')).'-'.substr($code, 0, 6),
            'date' => $to,
            'from' => $from,
            'to' => $to,
            'shop' => $shop,
            'narration' => $business.' - '.$shop.' - EPOS takings '.($same ? $f->format('j M Y') : $f->format('j M Y').' to '.$t->format('j M Y')),
            'lines' => [],
            'debits' => '0.00',
            'credits' => '0.00',
        ];
    }
}
