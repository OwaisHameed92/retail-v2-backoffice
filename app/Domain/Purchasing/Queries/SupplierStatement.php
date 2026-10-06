<?php

namespace App\Domain\Purchasing\Queries;

use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\Supplier;
use App\Domain\TillData\Models\SupplierCreditNote;
use App\Domain\TillData\Models\SupplierInvoice;
use App\Domain\TillData\Models\SupplierPayment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

/**
 * Supplier statements (module 5.2): what the business owes each supplier = invoices − credit notes − payments, from
 * the shops' own documents (company scope; one shop when filtered or for a one-shop user). Draft invoices (still
 * being keyed in) and reversed payments do not count. All sums are exact decimals (Money), never floats.
 */
final class SupplierStatement
{
    /**
     * Every supplier with documents, and its balance.
     *
     * @return list<array{id: string, name: string, invoiced: string, credited: string, paid: string, balance: string}>
     */
    public static function balances(?string $shop): array
    {
        $sum = fn (Builder $q, string $column) => $q->groupBy('supplier_id')->selectRaw("supplier_id, sum({$column}) as total")->pluck('total', 'supplier_id');
        $invoiced = $sum(self::invoices($shop), 'gross_amount');
        $credited = $sum(self::credits($shop), 'gross_amount');
        $paid = $sum(self::payments($shop), 'amount');
        $ids = array_values(array_unique([...$invoiced->keys(), ...$credited->keys(), ...$paid->keys()]));

        return Supplier::query()->withTrashed()->whereKey($ids)->orderBy('name')->get(['id', 'name'])->map(function (Supplier $s) use ($invoiced, $credited, $paid) {
            $row = ['invoiced' => Money::normalise($invoiced[$s->id] ?? 0), 'credited' => Money::normalise($credited[$s->id] ?? 0), 'paid' => Money::normalise($paid[$s->id] ?? 0)];

            return ['id' => $s->id, 'name' => (string) $s->name, ...$row, 'balance' => self::balance($row['invoiced'], $row['credited'], $row['paid'])];
        })->values()->all();
    }

    /**
     * Totals of the balances list: each column, what is owed (positive balances) and what suppliers owe back.
     *
     * @param  list<array{invoiced: string, credited: string, paid: string, balance: string}>  $rows
     * @return array{invoiced: string, credited: string, paid: string, balance: string, owed: string, inCredit: string}
     */
    public static function summary(array $rows): array
    {
        $col = fn (string $key) => Money::sum(array_column($rows, $key));
        $balances = array_column($rows, 'balance');

        return [
            'invoiced' => $col('invoiced'), 'credited' => $col('credited'), 'paid' => $col('paid'), 'balance' => $col('balance'),
            'owed' => Money::sum(array_filter($balances, fn (string $b) => Money::compare($b, '0') > 0)),
            'inCredit' => Money::sub('0', Money::sum(array_filter($balances, fn (string $b) => Money::isNegative($b)))),
        ];
    }

    /**
     * One supplier's statement for a period: the opening balance, each document with a running balance, the closing
     * balance.
     *
     * @return array<string, mixed>
     */
    public static function for(Supplier $supplier, ?string $shop, string $from, string $to): array
    {
        $before = fn (Builder $q, string $date, string $column) => Money::normalise($q->where('supplier_id', $supplier->id)->where($date, '<', $from)->sum($column) ?: 0);
        $opening = self::balance(
            $before(self::invoices($shop), 'invoice_date', 'gross_amount'),
            $before(self::credits($shop), 'credit_date', 'gross_amount'),
            $before(self::payments($shop), 'payment_date', 'amount'),
        );

        $in = fn (string $date) => [$supplier->id, $date, $from, $to];
        $entries = [];

        foreach (self::within(self::invoices($shop), ...$in('invoice_date')) as $i) {
            $entries[] = self::entry('invoice', 'invoices', $i, $i->invoice_date->format('Y-m-d'), $i->invoice_number, $i->gross_amount, '0.00', $i->due_date->format('Y-m-d'));
        }

        foreach (self::within(self::credits($shop), ...$in('credit_date')) as $c) {
            $entries[] = self::entry('credit', 'credit-notes', $c, $c->credit_date->format('Y-m-d'), $c->credit_note_number, '0.00', $c->gross_amount, $c->reason ?: null);
        }

        foreach (self::within(self::payments($shop), ...$in('payment_date')) as $p) {
            $entries[] = self::entry('payment', null, $p, $p->payment_date->format('Y-m-d'), $p->reference ?: 'Payment', '0.00', $p->amount, $p->method?->value);
        }

        $order = ['invoice' => 0, 'credit' => 1, 'payment' => 2];
        usort($entries, fn (array $a, array $b) => [$a['date'], $order[$a['type']], $a['id']] <=> [$b['date'], $order[$b['type']], $b['id']]);

        $running = $opening;
        foreach ($entries as &$entry) {
            $running = Money::sub(Money::add($running, $entry['debit']), $entry['credit']);
            $entry['balance'] = $running;
        }
        unset($entry);

        $total = fn (string $type, string $side) => Money::sum(array_map(fn (array $e) => $e[$side], array_filter($entries, fn (array $e) => $e['type'] === $type)));

        return [
            'supplier' => ['id' => $supplier->id, 'name' => (string) $supplier->name, 'account' => $supplier->account_number ?: null, 'terms' => $supplier->payment_terms_days],
            'period' => ['from' => $from, 'to' => $to],
            'opening' => $opening,
            'entries' => $entries,
            'totals' => [
                'invoiced' => $total('invoice', 'debit'), 'credited' => $total('credit', 'credit'), 'paid' => $total('payment', 'credit'),
                'reduced' => Money::sum(array_column($entries, 'credit')),
            ],
            'closing' => $running,
        ];
    }

    /** @return array{0: string, 1: string} a valid period, by default the last three months (London) */
    public static function period(mixed $from, mixed $to): array
    {
        $valid = fn (mixed $d) => is_string($d) && CarbonImmutable::hasFormat($d, 'Y-m-d') ? $d : null;
        $today = CarbonImmutable::now(Country::zone());
        $to = $valid($to) ?? $today->format('Y-m-d');
        $from = $valid($from) ?? $today->subMonthsNoOverflow(3)->startOfMonth()->format('Y-m-d');

        return $from <= $to ? [$from, $to] : [$to, $from];
    }

    public static function balance(string $invoiced, string $credited, string $paid): string
    {
        return Money::sub(Money::sub($invoiced, $credited), $paid);
    }

    /** @return array<string, mixed> */
    private static function entry(string $type, ?string $kind, Model $doc, ?string $date, ?string $reference, ?string $debit, ?string $credit, ?string $detail): array
    {
        return [
            'id' => (string) $doc->getKey(), 'type' => $type, 'kind' => $kind, 'date' => (string) $date, 'reference' => $reference ?: '—',
            'detail' => $detail, 'shopId' => $doc->getAttribute('branch_id'),
            'debit' => Money::normalise($debit ?? 0), 'credit' => Money::normalise($credit ?? 0), 'balance' => '0.00',
        ];
    }

    /**
     * One supplier's documents dated within the period, oldest first.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @return EloquentCollection<int, T>
     */
    private static function within(Builder $query, string $supplierId, string $date, string $from, string $to): EloquentCollection
    {
        return $query->where('supplier_id', $supplierId)->whereBetween($date, [$from, $to])->orderBy($date)->orderBy('id')->get();
    }

    /** @return Builder<SupplierInvoice> */
    private static function invoices(?string $shop): Builder
    {
        return SupplierInvoice::query()->where(fn ($q) => $q->where('status', '!=', 'draft')->orWhereNull('status'))
            ->when($shop !== null, fn ($q) => $q->where('branch_id', $shop));
    }

    /** @return Builder<SupplierCreditNote> */
    private static function credits(?string $shop): Builder
    {
        return SupplierCreditNote::query()->when($shop !== null, fn ($q) => $q->where('branch_id', $shop));
    }

    /** @return Builder<SupplierPayment> */
    private static function payments(?string $shop): Builder
    {
        return SupplierPayment::query()->where(fn ($q) => $q->where('is_reversed', false)->orWhereNull('is_reversed'))
            ->when($shop !== null, fn ($q) => $q->where('branch_id', $shop));
    }
}
