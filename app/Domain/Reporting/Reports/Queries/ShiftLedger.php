<?php

namespace App\Domain\Reporting\Reports\Queries;

use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Shared\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Shifts and Z reports (DASHBOARD.md §2.4–2.5) from the till's own rows: shifts closed in the range (local days of
 * `closedAt`), their `ShiftTender` lines (expected against counted, variance = declared − expected, negative =
 * short) and the Z reports whose period ended in the range. The till's figures are shown as sent, never recomputed.
 * Pages are read first, then their tender lines and Z reports by shift id (indexed).
 */
final class ShiftLedger
{
    /**
     * @return array{shifts: int, variance: string, short: int, over: int, warnings: int, open: int}
     */
    public function summary(ReportScope $scope): array
    {
        $row = $this->closed($scope)->selectRaw(
            'COUNT(*) as shifts, SUM(ROUND(COALESCE(sh.variance_total, 0) * 100)) as pence, '
            .'SUM(CASE WHEN sh.variance_total < 0 THEN 1 ELSE 0 END) as short_count, SUM(CASE WHEN sh.variance_total > 0 THEN 1 ELSE 0 END) as over_count'
        )->first();

        $warnings = $this->closed($scope)
            ->join('z_reports as z', fn (JoinClause $j) => $j->on('z.shift_id', '=', 'sh.id')->on('z.company_id', '=', 'sh.company_id'))
            ->whereNull('z.deleted_at')
            ->whereRaw("REPLACE(z.totals_json, ' ', '') LIKE ?", ['%"HasVarianceWarning":true%'])
            ->distinct()->count('sh.id');

        $open = $this->shops($scope, DB::table('shifts as sh'))->where('sh.status', 'open')->whereNull('sh.deleted_at')->count();

        return [
            'shifts' => (int) ($row->shifts ?? 0),
            'variance' => Money::round(bcdiv((string) (int) round((float) ($row->pence ?? 0)), '100', 2)),
            'short' => (int) ($row->short_count ?? 0),
            'over' => (int) ($row->over_count ?? 0),
            'warnings' => $warnings,
            'open' => $open,
        ];
    }

    /**
     * Closed shifts, newest first, with their tender totals (all and cash) and Z report.
     *
     * @return array{total: int, rows: list<array<string, string|int|bool|null>>, tenders: list<array<string, string|null>>}
     */
    public function shifts(ReportScope $scope, int $limit, int $offset): array
    {
        $total = $this->closed($scope)->count();
        $shifts = $this->labelled($this->closed($scope))
            ->select(['sh.id', 'sh.opened_at', 'sh.closed_at', 'sh.opening_float', 'sh.variance_total', 'sh.mode', 'sh.closed_by', 'b.name as shop', 'g.code as till_code', 'g.name as till_name', 'u.name as user_name'])
            ->orderByDesc('sh.closed_at')->orderByDesc('sh.id')->offset($offset)->limit($limit)->get();
        $ids = $shifts->pluck('id')->all();

        $lines = $ids === [] ? collect() : DB::table('shift_tenders as st')
            ->leftJoin('payment_types as pt', fn (JoinClause $j) => $j->on('pt.id', '=', 'st.payment_type_id')->on('pt.company_id', '=', 'st.company_id'))
            ->where('st.company_id', $this->companyOf($scope))->whereIn('st.shift_id', $ids)->whereNull('st.deleted_at')
            ->orderBy('pt.name')->get(['st.shift_id', 'st.expected', 'st.declared', 'st.terminal_total', 'st.variance', 'pt.name', 'pt.is_cash']);
        $zs = $this->zByShift($scope, $ids);
        $byShift = $lines->groupBy('shift_id');

        $rows = [];
        $tenders = [];

        foreach ($shifts as $s) {
            $mine = $byShift->get($s->id, collect());
            $cash = $mine->filter(fn ($l) => (bool) $l->is_cash);
            $z = $zs[$s->id] ?? null;
            $till = self::till($s->till_code, $s->till_name);

            $rows[] = [
                'id' => (string) $s->id,
                'closedAt' => self::iso($s->closed_at), 'openedAt' => self::iso($s->opened_at),
                'shop' => (string) ($s->shop ?? ''), 'till' => $till, 'user' => (string) ($s->user_name ?? $s->closed_by ?? ''),
                'float' => self::money($s->opening_float),
                'expected' => self::sum($mine, 'expected'), 'declared' => self::sum($mine, 'declared'),
                'cashExpected' => self::sum($cash, 'expected'), 'cashDeclared' => self::sum($cash, 'declared'), 'cashVariance' => self::sum($cash, 'variance'),
                'variance' => self::money($s->variance_total) ?? self::sum($mine, 'variance'),
                'z' => $z['sequenceNo'] ?? null,
                'warning' => $z['warning'] ?? false,
            ];

            foreach ($mine as $l) {
                $tenders[] = [
                    'closedAt' => self::iso($s->closed_at), 'till' => $till, 'tender' => (string) ($l->name ?? 'Unknown'),
                    'expected' => self::money($l->expected), 'declared' => self::money($l->declared), 'terminal' => self::money($l->terminal_total), 'variance' => self::money($l->variance),
                ];
            }
        }

        return ['total' => $total, 'rows' => $rows, 'tenders' => $tenders];
    }

    /**
     * Z reports whose period ended in the range, newest first.
     *
     * @return array{total: int, rows: list<array<string, string|int|bool|null>>}
     */
    public function zReports(ReportScope $scope, int $limit, int $offset): array
    {
        [$start, $end] = $this->window($scope);
        $base = fn () => $this->shops($scope, DB::table('z_reports as z'), 'z')->whereNull('z.deleted_at')
            ->where('z.period_end', '>=', $start)->where('z.period_end', '<', $end);
        $total = $base()->count();
        $rows = $base()
            ->leftJoin('branches as b', fn (JoinClause $j) => $j->on('b.id', '=', 'z.branch_id')->on('b.company_id', '=', 'z.company_id'))
            ->leftJoin('registers as g', fn (JoinClause $j) => $j->on('g.id', '=', 'z.register_id')->on('g.company_id', '=', 'z.company_id'))
            ->orderByDesc('z.period_end')->orderByDesc('z.id')->offset($offset)->limit($limit)
            ->get(['z.id', 'z.sequence_no', 'z.period_start', 'z.period_end', 'z.generated_at', 'z.printed_at', 'z.reprint_count', 'z.totals_json', 'b.name as shop', 'g.code as till_code', 'g.name as till_name']);

        return ['total' => $total, 'rows' => $rows->map(function (object $z) {
            $totals = self::totals($z->totals_json);

            return [
                'id' => (string) $z->id, 'sequenceNo' => $z->sequence_no === null ? null : (int) $z->sequence_no,
                'shop' => (string) ($z->shop ?? ''), 'till' => self::till($z->till_code, $z->till_name),
                'periodStart' => self::iso($z->period_start), 'periodEnd' => self::iso($z->period_end), 'printedAt' => self::iso($z->printed_at),
                'reprints' => (int) ($z->reprint_count ?? 0), 'variance' => $totals['variance'], 'warning' => $totals['warning'],
            ];
        })->values()->all()];
    }

    /**
     * @param  list<string>  $shiftIds
     * @return array<string, array{sequenceNo: int|null, warning: bool}>
     */
    private function zByShift(ReportScope $scope, array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }

        $out = [];

        foreach (DB::table('z_reports')->where('company_id', $this->companyOf($scope))->whereIn('shift_id', $shiftIds)->whereNull('deleted_at')->get(['shift_id', 'sequence_no', 'totals_json']) as $z) {
            $out[(string) $z->shift_id] = ['sequenceNo' => $z->sequence_no === null ? null : (int) $z->sequence_no, 'warning' => self::totals($z->totals_json)['warning']];
        }

        return $out;
    }

    /** Shifts of the scope closed in the range (UTC window of the local days). */
    private function closed(ReportScope $scope): Builder
    {
        [$start, $end] = $this->window($scope);

        return $this->shops($scope, DB::table('shifts as sh'))
            ->where('sh.status', 'closed')->whereNull('sh.deleted_at')
            ->where('sh.closed_at', '>=', $start)->where('sh.closed_at', '<', $end);
    }

    private function labelled(Builder $query): Builder
    {
        return $query
            ->leftJoin('branches as b', fn (JoinClause $j) => $j->on('b.id', '=', 'sh.branch_id')->on('b.company_id', '=', 'sh.company_id'))
            ->leftJoin('registers as g', fn (JoinClause $j) => $j->on('g.id', '=', 'sh.register_id')->on('g.company_id', '=', 'sh.company_id'))
            ->leftJoin('till_users as u', fn (JoinClause $j) => $j->on('u.id', '=', 'sh.user_id')->on('u.company_id', '=', 'sh.company_id'));
    }

    private function shops(ReportScope $scope, Builder $query, string $alias = 'sh'): Builder
    {
        return $query->where("{$alias}.company_id", $this->companyOf($scope))
            ->when($scope->branchIds !== null, fn (Builder $q) => $q->whereIn("{$alias}.branch_id", $scope->branchIds ?? []))
            ->when($scope->registerIds !== null, fn (Builder $q) => $q->whereIn("{$alias}.register_id", $scope->registerIds ?? []));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function window(ReportScope $scope): array
    {
        return [TradingDay::window($scope->from->toDateString())[0]->format('Y-m-d H:i:s'), TradingDay::window($scope->to->toDateString())[1]->format('Y-m-d H:i:s')];
    }

    /**
     * `ZReport.totalsJson` (PascalCase ZReportDto): the till's own variance total and warning flag.
     *
     * @return array{variance: string|null, warning: bool}
     */
    private static function totals(?string $json): array
    {
        $data = is_string($json) ? json_decode($json, true) : null;

        return is_array($data)
            ? ['variance' => is_numeric($data['VarianceTotal'] ?? null) ? Money::normalise($data['VarianceTotal']) : null, 'warning' => ($data['HasVarianceWarning'] ?? false) === true]
            : ['variance' => null, 'warning' => false];
    }

    /**
     * @param  Collection<int, object>  $lines
     */
    private static function sum($lines, string $column): ?string
    {
        return $lines->isEmpty() ? null : Money::sum($lines->map(fn ($l) => $l->{$column} ?? 0));
    }

    private static function money(mixed $value): ?string
    {
        return $value === null ? null : Money::normalise($value);
    }

    private static function iso(?string $utc): ?string
    {
        return $utc === null ? null : str_replace(' ', 'T', substr($utc, 0, 19)).'Z';
    }

    private static function till(?string $code, ?string $name): string
    {
        $label = trim((string) $code.' – '.(string) $name, ' –');

        return $label !== '' ? $label : 'Unknown till';
    }

    private function companyOf(ReportScope $scope): string
    {
        if ($scope->admin || $scope->companyId === null) {
            throw new InvalidArgumentException('Shifts are read for one business (tenant scope) only.');
        }

        return $scope->companyId;
    }
}
