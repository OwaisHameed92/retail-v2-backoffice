<?php

namespace App\Domain\Shared\Support;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Server side of the React `DataTable`: reads `search`, `sort`, `direction`, `page`, `perPage` from the request,
 * applies only whitelisted columns to an Eloquent builder and returns `{data, meta}` for the page props.
 *
 *     $table = TableQuery::from($request)
 *         ->searchable(['name', 'email'])
 *         ->sortable(['name', 'created_at'])
 *         ->defaultSort('created_at', 'desc');
 *
 *     return Inertia::render('admin/tenants/index', ['companies' => $table->paginate(Company::query(), fn ($c) => [...])]);
 */
final class TableQuery
{
    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    /** @var list<string> */
    private array $searchable = [];

    /** @var list<string> */
    private array $sortable = [];

    private ?string $defaultSort = null;

    private string $defaultDirection = 'asc';

    private int $defaultPerPage = 25;

    public function __construct(private readonly Request $request) {}

    public static function from(Request $request): self
    {
        return new self($request);
    }

    /**
     * @param  list<string>  $columns  Columns matched with LIKE against `search`.
     */
    public function searchable(array $columns): self
    {
        $this->searchable = $columns;

        return $this;
    }

    /**
     * @param  list<string>  $columns  The only columns `sort` may name.
     */
    public function sortable(array $columns): self
    {
        $this->sortable = $columns;

        return $this;
    }

    public function defaultSort(string $column, string $direction = 'asc'): self
    {
        $this->defaultSort = $column;
        $this->defaultDirection = $direction === 'desc' ? 'desc' : 'asc';

        return $this;
    }

    public function defaultPerPage(int $perPage): self
    {
        $this->defaultPerPage = $perPage;

        return $this;
    }

    public function search(): ?string
    {
        $search = trim((string) $this->request->string('search'));

        return $search === '' ? null : mb_substr($search, 0, 100);
    }

    /** The requested sort column, or null when it is missing or not whitelisted. */
    public function sort(): ?string
    {
        $sort = (string) $this->request->string('sort');

        return in_array($sort, $this->sortable, true) ? $sort : null;
    }

    public function direction(): string
    {
        if ($this->sort() === null) {
            return $this->defaultDirection;
        }

        return strtolower((string) $this->request->string('direction')) === 'desc' ? 'desc' : 'asc';
    }

    public function page(): int
    {
        return max(1, $this->request->integer('page', 1));
    }

    public function perPage(): int
    {
        $perPage = $this->request->integer('perPage', $this->defaultPerPage);

        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : $this->defaultPerPage;
    }

    /**
     * Apply search and sort to the builder (no pagination).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query): Builder
    {
        $search = $this->search();

        if ($search !== null && $this->searchable !== []) {
            // Not escaping % and _: a wildcard typed by the user only widens their own search, and
            // backslash escaping differs between SQLite and MySQL.
            $like = '%'.$search.'%';

            $query->where(function (Builder $q) use ($like): void {
                foreach ($this->searchable as $column) {
                    $q->orWhere($column, 'like', $like);
                }
            });
        }

        $sort = $this->sort() ?? $this->defaultSort;

        if ($sort !== null) {
            $query->orderBy($sort, $this->direction());
        }

        // Stable order across pages.
        $model = $query->getModel();
        if (! in_array($sort, [$model->getKeyName(), $model->getQualifiedKeyName()], true)) {
            $query->orderBy($model->getQualifiedKeyName(), $this->direction());
        }

        return $query;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginator(Builder $query): LengthAwarePaginator
    {
        return $this->apply($query)
            ->paginate($this->perPage(), ['*'], 'page', $this->page())
            ->withQueryString();
    }

    /**
     * Paginate and shape the result for the React `DataTable`.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  (Closure(TModel): mixed)|null  $map  Turns each model into the row sent to React.
     * @return array{data: list<mixed>, meta: array{page: int, perPage: int, total: int, lastPage: int, search: string|null, sort: string|null, direction: string}}
     */
    public function paginate(Builder $query, ?Closure $map = null): array
    {
        $paginator = $this->paginator($query);
        $items = $paginator->items();

        return [
            'data' => array_values($map === null ? $items : array_map($map, $items)),
            'meta' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'search' => $this->search(),
                'sort' => $this->sort(),
                'direction' => $this->direction(),
            ],
        ];
    }
}
