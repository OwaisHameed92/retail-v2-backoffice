<?php

namespace App\Domain\Reporting\Build;

use App\Domain\Reporting\ReportTables;
use App\Domain\Reporting\Support\Units;
use App\Domain\Shared\Support\Money;

/**
 * Turns SaleFacts' grouped rows into the rows of every `rpt_*` table for one shop's trading days (DASHBOARD.md
 * §4.2–4.6). Pure: nothing is written. Sums are whole units added with bcmath; refunds are already negative in the
 * raw rows, so everything is a signed sum and only the "refunds" columns are negated to show them positive (§1.5).
 */
final class ReportRowBuilder
{
    /** @var array<string, array<string, array<string, mixed>>> table => row key => row (decimals in units) */
    private array $rows = [];

    /** @var array<string, string> table|row key => sort key of the label kept (newest wins) */
    private array $labelAt = [];

    /**
     * @param  list<string>  $days
     * @return array<string, list<array<string, mixed>>> table => rows ready to insert
     */
    public static function build(string $companyId, string $branchId, array $days, string $rebuiltAt): array
    {
        $facts = new SaleFacts($companyId, $branchId, $days);
        $builder = new self($companyId, $branchId);

        foreach ($facts->sales() as $row) {
            $builder->sale($row);
        }

        foreach ($facts->vat() as $row) {
            $builder->vat($row);
        }

        foreach ($facts->lines() as $row) {
            $builder->line($row);
        }

        foreach ($facts->staffReturns() as $row) {
            $builder->add(ReportTables::STAFF_DAILY, self::day($row), ['register_id' => self::id($row->register_id), 'user_id' => self::id($row->user_id)], ['refund_gross' => Units::neg(Units::of($row->goods))]);
        }

        foreach ($facts->payments() as $row) {
            $builder->payment($row);
        }

        return $builder->finish($rebuiltAt);
    }

    private function __construct(private readonly string $companyId, private readonly string $branchId) {}

    private function sale(object $r): void
    {
        $day = self::day($r);
        $register = self::id($r->register_id);
        $txn = (int) $r->txn_count;
        $refunds = (int) $r->refund_count;
        $counts = ['txn_count' => $txn, 'refund_count' => $refunds, 'void_count' => (int) $r->void_count];

        $this->add(ReportTables::SALES_DAILY, $day, ['register_id' => $register], [
            'takings' => Units::of($r->takings),
            'container_deposits' => Units::of($r->container_deposits),
            'void_total' => Units::of($r->void_total),
        ], $counts);
        $this->add(ReportTables::STAFF_DAILY, $day, ['register_id' => $register, 'user_id' => self::id($r->user_id)], [], $counts);

        if ($txn + $refunds > 0 && $r->trading_hour !== null) {
            $this->add(ReportTables::SALES_HOURLY, $day, ['register_id' => $register, 'hour' => (int) $r->trading_hour], [], ['txn_count' => $txn]);
        }
    }

    private function vat(object $r): void
    {
        $day = self::day($r);
        $register = self::id($r->register_id);
        $net = Units::of($r->net);
        $vat = Units::of($r->vat);
        $gross = Units::of($r->gross);

        $this->add(ReportTables::SALES_DAILY, $day, ['register_id' => $register], ['gross' => $gross, 'net' => $net, 'vat' => $vat]);
        $this->add(ReportTables::STAFF_DAILY, $day, ['register_id' => $register, 'user_id' => self::id($r->user_id)], ['gross' => $gross, 'net' => $net]);
        $this->add(ReportTables::SALES_HOURLY, $day, ['register_id' => $register, 'hour' => (int) $r->trading_hour], ['net' => $net, 'gross' => $gross]);
        $this->add(ReportTables::VAT_DAILY, $day, [
            'register_id' => $register,
            'vat_rate_id' => self::id($r->vat_rate_id),
            'percentage' => Money::normalise($r->percentage ?? 0, 4),
        ], ['net' => $net, 'vat' => $vat, 'gross' => $gross], [], ['code' => (string) ($r->code ?? '')]);
    }

    private function line(object $r): void
    {
        $day = self::day($r);
        $register = self::id($r->register_id);
        $returned = (int) $r->returned === 1;
        $goods = Units::of($r->goods);
        $vat = Units::of($r->vat);
        $net = Units::sub($goods, $vat);
        $qty = Units::of($r->qty);
        $cost = Units::of($r->cost);

        $this->add(ReportTables::SALES_DAILY, $day, ['register_id' => $register], $returned
            ? ['refund_gross' => Units::neg($goods), 'refund_net' => Units::neg($net), 'cost' => $cost]
            : ['discount' => Units::of($r->discount), 'promo' => Units::of($r->promo), 'coupon' => Units::of($r->coupon), 'cost' => $cost]);

        $product = ['gross' => $goods, 'net' => $net, 'vat' => $vat, 'cost' => $cost];
        $product += $returned
            ? ['refund_qty' => Units::neg($qty), 'refund_net' => Units::neg($net)]
            : ['qty' => $qty, 'discount' => Units::of($r->discount), 'promo' => Units::of($r->promo)];
        $name = mb_substr((string) ($r->name ?? ''), 0, 255);

        $this->add(ReportTables::PRODUCT_DAILY, $day, ['register_id' => $register, 'product_id' => self::id($r->product_id)], $product, [], ['last_name' => $name], (string) $r->latest.'|'.$name);
    }

    private function payment(object $r): void
    {
        $amount = Units::of($r->amount);
        $name = mb_substr((string) ($r->payment_type_name ?? ''), 0, 255);

        $this->add(ReportTables::TENDER_DAILY, self::day($r), [
            'register_id' => self::id($r->register_id),
            'payment_type_id' => self::id($r->payment_type_id),
        ], (int) $r->refund === 1 ? ['amount' => $amount, 'refunds' => Units::neg($amount)] : ['amount' => $amount], ['count' => (int) $r->payments], ['payment_type_name' => $name], (string) $r->latest.'|'.$name);
    }

    /**
     * @param  array<string, string|int>  $keys
     * @param  array<string, string>  $units  decimal column => units to add
     * @param  array<string, int>  $counts
     * @param  array<string, string>  $labels
     * @param  string|null  $labelAt  labels replace the stored ones when this sorts later (null: first non-empty wins)
     */
    private function add(string $table, string $day, array $keys, array $units, array $counts = [], array $labels = [], ?string $labelAt = null): void
    {
        $scope = ['company_id' => $this->companyId, 'branch_id' => $this->branchId, 'trading_day' => $day];
        $key = ReportTables::rowKey($table, [...$scope, ...$keys]);
        $def = ReportTables::TABLES[$table];

        $row = $this->rows[$table][$key] ??= [
            ...$scope,
            ...$keys,
            ...array_fill_keys(array_keys($def['decimals']), '0'),
            ...array_fill_keys($def['counts'], 0),
            ...array_fill_keys($def['labels'], ''),
        ];

        foreach ($units as $column => $value) {
            $row[$column] = Units::add((string) $row[$column], $value);
        }

        foreach ($counts as $column => $value) {
            $row[$column] = (int) $row[$column] + $value;
        }

        $slot = $table.'|'.$key;

        foreach ($labels as $column => $value) {
            $newer = $labelAt === null
                ? $row[$column] === ''
                : ! isset($this->labelAt[$slot]) || strcmp($labelAt, $this->labelAt[$slot]) > 0;

            if ($newer) {
                $row[$column] = $value;
            }
        }

        if ($labelAt !== null && (! isset($this->labelAt[$slot]) || strcmp($labelAt, $this->labelAt[$slot]) > 0)) {
            $this->labelAt[$slot] = $labelAt;
        }

        $this->rows[$table][$key] = $row;
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function finish(string $rebuiltAt): array
    {
        $out = array_fill_keys(ReportTables::names(), []);

        foreach ($this->rows as $table => $rows) {
            $def = ReportTables::TABLES[$table];
            ksort($rows);

            foreach ($rows as $row) {
                foreach ($def['decimals'] as $column => $scale) {
                    $row[$column] = Units::decimal((string) $row[$column], $scale);
                }

                $row['rebuilt_at'] = $rebuiltAt;
                $ordered = [];

                foreach (ReportTables::columns($table) as $column) {
                    $ordered[$column] = $row[$column];
                }

                $out[$table][] = $ordered;
            }
        }

        return $out;
    }

    private static function day(object $row): string
    {
        return substr((string) $row->trading_day, 0, 10);
    }

    private static function id(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }
}
