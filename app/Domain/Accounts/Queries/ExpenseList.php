<?php

namespace App\Domain\Accounts\Queries;

use App\Domain\Accounts\Data\AccountsFilters;
use App\Domain\Cash\Support\CashLookup;
use App\Domain\Reporting\Support\Units;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Models\Expense;
use App\Domain\TillData\Models\Reason;
use App\Domain\TillData\Models\Supplier;
use App\Domain\TillData\Models\VatRate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Request;

/**
 * The shops' expenses (module 5.5), read only: `Expense` is branch-owned (ownership.json), so the till records,
 * edits and voids them. Listed by expense date; totals leave voided expenses out.
 */
final class ExpenseList
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request, AccountsFilters $filters): array
    {
        $table = TableQuery::from($request)->searchable(['expenses.payee_name', 'expenses.receipt_ref', 'expenses.note'])
            ->sortable(['expense_date', 'gross', 'net'])->defaultSort('expense_date', 'desc')->defaultPerPage(25);
        $page = $table->paginator(self::query($filters));
        /** @var list<Expense> $expenses */
        $expenses = $page->items();
        $shops = CashLookup::shops(array_map(fn (Expense $e) => $e->branch_id, $expenses));
        $suppliers = self::names(Supplier::class, array_map(fn (Expense $e) => $e->supplier_id, $expenses), 'name');
        $reasons = self::names(Reason::class, array_map(fn (Expense $e) => $e->reason_id, $expenses), 'text');
        $rates = self::names(VatRate::class, array_map(fn (Expense $e) => $e->vat_rate_id, $expenses), 'code');

        return [
            'expenses' => [
                'data' => array_map(fn (Expense $e) => [
                    'id' => $e->id,
                    'date' => $e->expense_date->format('Y-m-d'),
                    'shop' => CashLookup::name($shops, $e->branch_id),
                    'payee' => CashLookup::name($suppliers, $e->supplier_id) ?? ((string) $e->payee_name !== '' ? (string) $e->payee_name : null),
                    'reason' => CashLookup::name($reasons, $e->reason_id),
                    'receiptRef' => (string) $e->receipt_ref !== '' ? (string) $e->receipt_ref : null,
                    'vatReceipt' => (bool) $e->vat_receipt_held,
                    'net' => CashLookup::money($e->net),
                    'vat' => CashLookup::money($e->vat),
                    'vatRate' => CashLookup::name($rates, $e->vat_rate_id),
                    'gross' => CashLookup::money($e->gross),
                    'paidBy' => $e->payment_type?->value,
                    'note' => (string) $e->note !== '' ? (string) $e->note : null,
                    'voided' => (bool) $e->is_voided,
                    'voidReason' => (string) $e->void_reason !== '' ? (string) $e->void_reason : null,
                ], $expenses),
                'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage(), 'search' => $table->search(), 'sort' => $table->sort(), 'direction' => $table->direction()],
            ],
            'totals' => self::totals($filters),
        ];
    }

    /**
     * Net, VAT and gross of the expenses that count (not voided) in the dates, and the VAT you can reclaim (a VAT
     * receipt is held).
     *
     * @return array{count: int, net: string, vat: string, gross: string, reclaimableVat: string, unreclaimedVat: string}
     */
    public static function totals(AccountsFilters $filters): array
    {
        $row = self::query($filters)->where(fn (Builder $q) => $q->where('is_voided', false)->orWhereNull('is_voided'))
            ->toBase()->selectRaw(implode(', ', [
                'COUNT(*) as n',
                'SUM(ROUND(COALESCE(net, 0) * 100)) as net',
                'SUM(ROUND(COALESCE(vat, 0) * 100)) as vat',
                'SUM(ROUND(COALESCE(gross, 0) * 100)) as gross',
                'SUM(CASE WHEN vat_receipt_held = 1 THEN ROUND(COALESCE(vat, 0) * 100) ELSE 0 END) as rvat',
            ]))->first();
        $p = fn (string $k) => Units::decimal(Units::of($row->{$k} ?? null), 2);

        return ['count' => (int) ($row->n ?? 0), 'net' => $p('net'), 'vat' => $p('vat'), 'gross' => $p('gross'), 'reclaimableVat' => $p('rvat'), 'unreclaimedVat' => Units::decimal(Units::sub(Units::of($row->vat ?? null), Units::of($row->rvat ?? null)), 2)];
    }

    /**
     * @return Builder<Expense>
     */
    private static function query(AccountsFilters $filters): Builder
    {
        return Expense::query()
            ->where('expense_date', '>=', $filters->from)->where('expense_date', '<=', $filters->to)
            ->when($filters->shop !== null, fn (Builder $q) => $q->where('branch_id', $filters->shop));
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string|null>  $ids
     * @return array<string, string>
     */
    private static function names(string $model, array $ids, string $column): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => is_string($id) && $id !== '')));

        return $ids === [] ? [] : $model::query()->withoutGlobalScope(SoftDeletingScope::class)->whereKey($ids)
            ->pluck($column, 'id')->filter(fn ($v) => (string) $v !== '')->map(fn ($v) => (string) $v)->all();
    }
}
