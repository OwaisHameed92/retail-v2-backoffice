<?php

namespace App\Domain\Billing\Queries;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Shared\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * The admin invoice list across companies (documented admin escape hatch), with the business name joined for
 * search and sort.
 */
final class InvoiceQuery
{
    /** Sort keys the DataTable may send. */
    public const SORTABLE = ['sequence', 'company_name', 'period_start', 'total', 'balance', 'due_date', 'issue_date'];

    /** "open" = issued, partly paid or overdue. */
    public const STATUS_FILTERS = ['open', 'draft', 'issued', 'partiallyPaid', 'overdue', 'paid', 'void'];

    /**
     * @param  array{status?: string|null, company?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Builder<Invoice>
     */
    public static function admin(array $filters = [], ?string $search = null): Builder
    {
        $query = Invoice::withoutCompanyScope()
            ->select('invoices.*')
            ->addSelect('companies.name as company_name')
            ->join('companies', 'companies.id', '=', 'invoices.company_id')
            ->with('company');

        $status = $filters['status'] ?? null;

        if ($status === 'open') {
            $query->whereIn('invoices.status', InvoiceStatus::openValues());
        } elseif ($status !== null && InvoiceStatus::tryFrom($status) !== null) {
            $query->where('invoices.status', $status);
        }

        if (($filters['company'] ?? null) !== null) {
            $query->where('invoices.company_id', $filters['company']);
        }

        if (($filters['from'] ?? null) !== null) {
            $query->where('invoices.issue_date', '>=', $filters['from']);
        }

        if (($filters['to'] ?? null) !== null) {
            $query->where('invoices.issue_date', '<=', $filters['to']);
        }

        if ($search !== null) {
            $like = '%'.$search.'%';
            $query->where(fn (Builder $q) => $q->where('invoices.number', 'like', $like)->orWhere('companies.name', 'like', $like));
        }

        return $query;
    }

    /**
     * Totals of everything the filters match (all pages): the list's totals row.
     *
     * @param  Builder<Invoice>  $query
     * @return array{count: int, total: string, balance: string}
     */
    public static function totals(Builder $query): array
    {
        $rows = $query->clone()->reorder()->toBase()->get(['invoices.total', 'invoices.balance', 'invoices.status']);
        $counting = $rows->filter(fn (object $row) => $row->status !== InvoiceStatus::Void->value);

        return [
            'count' => $rows->count(),
            'total' => Money::sum($counting->pluck('total')),
            'balance' => Money::sum($counting->pluck('balance')),
        ];
    }

    /**
     * Invoice counts per status (for the filter).
     *
     * @return array<string, int>
     */
    public static function countsByStatus(): array
    {
        return Invoice::withoutCompanyScope()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')
            ->pluck('total', 'status')->map(fn ($count) => (int) $count)->all();
    }
}
