<?php

namespace App\Domain\Demo\Support;

use Illuminate\Support\Facades\DB;

/**
 * What the stored demo sales took, read back from the sales tables (so the cash-up, Z reports and journals agree
 * with the sales to the penny): per shift and tender, and per till and day the VAT bands and the cost of sales.
 * All amounts in whole pence.
 */
final class SaleTakings
{
    /**
     * Per shift: [branch, register, user, day, first, last, cash (amount − change), cashback, card, count].
     *
     * @param  array<string, true>  $shiftIds  only these shifts (the demo's own)
     * @return array<string, array{branch: string, register: string, user: string, day: string, first: string, last: string, cash: int, cashback: int, card: int, count: int}>
     */
    public static function byShift(DemoBusiness $b, array $shiftIds): array
    {
        $rows = DB::table('sales as s')->join('sale_payments as p', fn ($j) => $j->on('p.sale_id', '=', 's.id')->on('p.company_id', '=', 's.company_id'))
            ->where('s.company_id', $b->companyId)->where('s.status', 'completed')->whereBetween('s.trading_day', [$b->from, $b->today])
            ->groupBy('s.shift_id', 's.branch_id', 's.register_id', 's.user_id', 's.trading_day', 'p.payment_type_name')
            ->selectRaw('s.shift_id, s.branch_id, s.register_id, s.user_id, s.trading_day, p.payment_type_name as tender, MIN(s.completed_at) as first_at, '
                .'MAX(s.completed_at) as last_at, SUM(ROUND(p.amount * 100)) as amount, SUM(ROUND(p.change_given * 100)) as change_given, '
                .'SUM(ROUND(p.cashback * 100)) as cashback, COUNT(*) as n')
            ->get();
        $out = [];

        foreach ($rows as $r) {
            $id = (string) $r->shift_id;

            if (! isset($shiftIds[$id])) {
                continue;
            }

            $out[$id] ??= [
                'branch' => (string) $r->branch_id, 'register' => (string) $r->register_id, 'user' => (string) $r->user_id,
                'day' => substr((string) $r->trading_day, 0, 10), 'first' => (string) $r->first_at, 'last' => (string) $r->last_at,
                'cash' => 0, 'cashback' => 0, 'card' => 0, 'count' => 0,
            ];
            $s = &$out[$id];
            $s['first'] = min($s['first'], (string) $r->first_at);
            $s['last'] = max($s['last'], (string) $r->last_at);
            $s['count'] += (int) $r->n;

            if ($r->tender === 'Card') {
                $s['card'] += (int) $r->amount;
                $s['cashback'] += (int) $r->cashback;
            } else {
                $s['cash'] += (int) $r->amount - (int) $r->change_given;
            }

            unset($s);
        }

        return $out;
    }

    /**
     * Per till and day: net and VAT per band code, and the cost of sales.
     *
     * @return array<string, array{bands: array<string, array{net: int, vat: int}>, cost: int}> "register|day" => figures
     */
    public static function byTillDay(DemoBusiness $b): array
    {
        $out = [];
        $vats = DB::table('sale_vats as v')->join('sales as s', fn ($j) => $j->on('s.id', '=', 'v.sale_id')->on('s.company_id', '=', 'v.company_id'))
            ->where('s.company_id', $b->companyId)->where('s.status', 'completed')->whereBetween('s.trading_day', [$b->from, $b->today])
            ->groupBy('s.register_id', 's.trading_day', 'v.code')
            ->selectRaw('s.register_id, s.trading_day, v.code, SUM(ROUND(v.net * 100)) as net, SUM(ROUND(v.vat * 100)) as vat')->get();

        foreach ($vats as $v) {
            $key = $v->register_id.'|'.substr((string) $v->trading_day, 0, 10);
            $out[$key]['bands'][(string) $v->code] = ['net' => (int) $v->net, 'vat' => (int) $v->vat];
            $out[$key]['cost'] ??= 0;
        }

        $costs = DB::table('sale_lines as l')->join('sales as s', fn ($j) => $j->on('s.id', '=', 'l.sale_id')->on('s.company_id', '=', 'l.company_id'))
            ->where('s.company_id', $b->companyId)->where('s.status', 'completed')->whereBetween('s.trading_day', [$b->from, $b->today])
            ->groupBy('s.register_id', 's.trading_day')
            ->selectRaw('s.register_id, s.trading_day, SUM(ROUND(l.cost_at_sale * 100)) as cost')->get();

        foreach ($costs as $c) {
            $key = $c->register_id.'|'.substr((string) $c->trading_day, 0, 10);
            $out[$key]['bands'] ??= [];
            $out[$key]['cost'] = (int) $c->cost;
        }

        return $out;
    }
}
