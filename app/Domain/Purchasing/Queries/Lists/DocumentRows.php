<?php

namespace App\Domain\Purchasing\Queries\Lists;

use App\Domain\Purchasing\Data\PurchasingFilters;
use App\Domain\Purchasing\Support\PurchasingNames;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One purchasing list (module 5.2): its query, filters, search, sorting and row shape. Rows are the tills' (read
 * only) and are read in the company scope; a shop filter (always set for a one-shop user) narrows them to one shop.
 *
 * @template TModel of Model
 */
abstract class DocumentRows
{
    /** @return Builder<TModel> */
    abstract protected function base(): Builder;

    /** @return list<string> the statuses the status filter offers */
    abstract public function statuses(): array;

    /** @return list<string> */
    abstract protected function sortable(): array;

    /** @return array{0: string, 1: string} column, direction */
    abstract protected function defaultSort(): array;

    /** @param Builder<TModel> $query */
    abstract protected function search(Builder $query, string $like): void;

    /**
     * @param  list<TModel>  $rows
     * @return list<array<string, mixed>>
     */
    abstract protected function rows(array $rows, PurchasingNames $names): array;

    /**
     * @param  Builder<TModel>  $query  already filtered by shop
     * @return list<array{label: string, value: string, format: string, hint?: string, tone: string}>
     */
    abstract public function stats(Builder $query): array;

    /** @param Builder<TModel> $query */
    protected function status(Builder $query, string $status): void
    {
        $query->where($query->qualifyColumn('status'), $status);
    }

    /** @return Builder<TModel> the list for one shop (or every shop), before the other filters */
    public function scoped(?string $shop): Builder
    {
        $query = $this->base();

        if ($shop !== null) {
            $query->where($query->qualifyColumn('branch_id'), $shop);
        }

        return $query;
    }

    /** @return Builder<TModel> */
    public function filtered(PurchasingFilters $filters): Builder
    {
        $query = $this->scoped($filters->shop);

        if ($filters->supplier !== null) {
            $query->where($query->qualifyColumn('supplier_id'), $filters->supplier);
        }

        if ($filters->status !== null) {
            $this->status($query, $filters->status);
        }

        return $query;
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function page(TableQuery $table, PurchasingFilters $filters): array
    {
        $query = $this->filtered($filters);
        $search = $table->search();

        if ($search !== null) {
            $query->where(fn (Builder $q) => $this->search($q, '%'.$search.'%'));
        }

        [$column, $direction] = $this->defaultSort();
        $paginator = $table->sortable($this->sortable())->defaultSort($column, $direction)->paginator($query);
        /** @var list<TModel> $items */
        $items = array_values($paginator->items());

        return [
            'data' => $this->rows($items, PurchasingNames::for($items)),
            'meta' => [
                'page' => $paginator->currentPage(), 'perPage' => $paginator->perPage(), 'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(), 'search' => $search, 'sort' => $table->sort(), 'direction' => $table->direction(),
            ],
        ];
    }

    /**
     * A supplier-name search as a sub-query (the supplier list is company-wide master data).
     *
     * @param  Builder<TModel>  $query
     */
    protected function supplierMatch(Builder $query, string $like): void
    {
        $query->orWhereIn($query->qualifyColumn('supplier_id'), fn ($q) => $q->select('id')->from('suppliers')
            ->where('company_id', app(CurrentCompany::class)->id())
            ->where('name', 'like', $like));
    }

    /** @return array{label: string, value: string, format: string, hint?: string, tone: string} */
    protected static function stat(string $label, string|int $value, string $format, string $tone = 'neutral', ?string $hint = null): array
    {
        return ['label' => $label, 'value' => (string) $value, 'format' => $format, 'tone' => $tone, ...($hint === null ? [] : ['hint' => $hint])];
    }
}
