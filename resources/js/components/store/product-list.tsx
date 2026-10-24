import { currencySymbol } from '@/lib/country';
import { cn } from '@/lib/utils';
import { Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, SearchX, SlidersHorizontal } from 'lucide-react';
import { type FormEvent, useId, useState } from 'react';
import { ProductGrid } from './product-card';
import { type ListFilters, type Pagination, type StoreDepartment, type StoreProduct } from './types';

type Query = Record<string, string>;

/** The list's query as the page echoes it, without defaults (so shared links stay short). */
function queryOf(filters: ListFilters, extra: Query = {}): Query {
    const query: Query = { ...extra };

    if (filters.sort !== 'name') query.sort = filters.sort;
    if (filters.stock) query.stock = '1';
    if (filters.min) query.min = filters.min;
    if (filters.max) query.max = filters.max;

    return query;
}

function usePath(): string {
    return usePage().url.split('?')[0];
}

/**
 * A product list (department or search): sort and filters (in stock only, price range, other aisles), the grid,
 * pages. Every change is a plain GET, so the browser's back button and shared links work.
 */
export function ProductList({
    products,
    pagination,
    filters,
    base = {},
    departments = [],
    currentDepartment,
    empty,
}: {
    products: StoreProduct[];
    pagination: Pagination;
    filters: ListFilters;
    base?: Query;
    departments?: StoreDepartment[];
    currentDepartment?: string;
    empty: string;
}) {
    const path = usePath();
    const [loading, setLoading] = useState(false);
    const go = (next: Partial<ListFilters>) =>
        router.get(path, queryOf({ ...filters, ...next }, base), {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });

    return (
        <div className="flex flex-col gap-6 lg:flex-row lg:items-start">
            <aside aria-label="Filters" className="lg:sticky lg:top-24 lg:w-[240px] lg:shrink-0">
                <details className="group border-line rounded-2xl border bg-white lg:hidden">
                    <summary className="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 px-4 text-sm font-semibold [&::-webkit-details-marker]:hidden">
                        <span className="flex items-center gap-2">
                            <SlidersHorizontal aria-hidden="true" className="size-4" /> Filters
                        </span>
                        <span className="text-muted text-sm font-semibold group-open:hidden">Show</span>
                        <span className="text-muted hidden text-sm font-semibold group-open:inline">Hide</span>
                    </summary>
                    <div className="px-4 pb-4">
                        <FilterPanel filters={filters} go={go} departments={departments} currentDepartment={currentDepartment} />
                    </div>
                </details>
                <div className="border-line hidden rounded-2xl border bg-white p-5 lg:block">
                    <FilterPanel filters={filters} go={go} departments={departments} currentDepartment={currentDepartment} />
                </div>
            </aside>

            <section aria-label="Products" className="flex min-w-0 flex-1 flex-col gap-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-muted text-sm" aria-live="polite">
                        {pagination.total === 1 ? '1 product' : `${pagination.total.toLocaleString('en-GB')} products`}
                    </p>
                    <SortSelect value={filters.sort} onChange={(sort) => go({ sort })} />
                </div>
                <div aria-busy={loading} className={cn('transition-opacity duration-200', loading && 'pointer-events-none opacity-50')}>
                    {products.length > 0 ? (
                        <ProductGrid products={products} priority={4} />
                    ) : (
                        <div className="border-line flex flex-col items-center gap-3 rounded-2xl border bg-white px-6 py-12 text-center">
                            <span className="bg-brand-soft text-brand-text flex size-12 items-center justify-center rounded-full">
                                <SearchX aria-hidden="true" className="size-6" strokeWidth={1.6} />
                            </span>
                            <p className="font-display text-xl font-bold">Nothing here yet</p>
                            <p className="text-body max-w-md text-sm">{empty}</p>
                            {departments.length > 0 && (
                                <ul className="flex max-w-xl flex-wrap justify-center gap-2 pt-2">
                                    {departments.slice(0, 8).map((department) => (
                                        <li key={department.id}>
                                            <Link
                                                href={department.url}
                                                className="border-line hover:border-brand-text text-body inline-flex h-9 items-center rounded-full border px-3.5 text-[13px] font-medium transition"
                                            >
                                                {department.name}
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}
                </div>
                <Pages pagination={pagination} query={queryOf(filters, base)} />
            </section>
        </div>
    );
}

function SortSelect({ value, onChange }: { value: ListFilters['sort']; onChange: (value: ListFilters['sort']) => void }) {
    const id = useId();

    return (
        <div className="flex items-center gap-2.5">
            <label htmlFor={id} className="text-muted text-sm">
                Sort by
            </label>
            <select
                id={id}
                value={value}
                onChange={(e) => onChange(e.target.value as ListFilters['sort'])}
                className="text-ink border-line h-10 rounded-full border bg-white px-3.5 text-sm font-medium"
            >
                <option value="name">Name, A to Z</option>
                <option value="priceAsc">Price, low to high</option>
                <option value="priceDesc">Price, high to low</option>
                <option value="newest">Newest first</option>
            </select>
        </div>
    );
}

function FilterPanel({
    filters,
    go,
    departments,
    currentDepartment,
}: {
    filters: ListFilters;
    go: (next: Partial<ListFilters>) => void;
    departments: StoreDepartment[];
    currentDepartment?: string;
}) {
    const id = useId();
    const [min, setMin] = useState(filters.min ?? '');
    const [max, setMax] = useState(filters.max ?? '');
    const apply = (event: FormEvent) => {
        event.preventDefault();
        go({ min: min.trim() || null, max: max.trim() || null });
    };
    const field = 'h-10 w-full min-w-0 rounded-lg border border-line bg-white pr-3 pl-7 text-sm text-ink';

    return (
        <div className="flex flex-col gap-5">
            <label className="bg-brand-soft flex min-h-11 cursor-pointer items-center gap-3 rounded-xl px-3.5 text-sm font-semibold">
                <input
                    type="checkbox"
                    checked={filters.stock}
                    onChange={(e) => go({ stock: e.target.checked })}
                    className="size-4 accent-[var(--color-brand)]"
                />
                In stock only
            </label>

            <form onSubmit={apply} className="flex flex-col gap-3">
                <fieldset className="flex flex-col gap-3">
                    <legend className="mb-2 text-sm font-semibold">Price</legend>
                    <div className="flex items-center gap-2">
                        <div className="relative flex-1">
                            <label htmlFor={`${id}-min`} className="sr-only">
                                Lowest price
                            </label>
                            <span aria-hidden="true" className="text-muted absolute top-1/2 left-3 -translate-y-1/2">
                                {currencySymbol()}
                            </span>
                            <input
                                id={`${id}-min`}
                                inputMode="decimal"
                                value={min}
                                onChange={(e) => setMin(e.target.value)}
                                placeholder="0"
                                className={field}
                            />
                        </div>
                        <span className="text-muted text-sm">to</span>
                        <div className="relative flex-1">
                            <label htmlFor={`${id}-max`} className="sr-only">
                                Highest price
                            </label>
                            <span aria-hidden="true" className="text-muted absolute top-1/2 left-3 -translate-y-1/2">
                                {currencySymbol()}
                            </span>
                            <input
                                id={`${id}-max`}
                                inputMode="decimal"
                                value={max}
                                onChange={(e) => setMax(e.target.value)}
                                placeholder="Any"
                                className={field}
                            />
                        </div>
                    </div>
                </fieldset>
                <button type="submit" className="bg-brand text-on-brand hover:bg-brand-hover h-10 rounded-full text-sm font-semibold transition">
                    Apply price
                </button>
            </form>

            {departments.length > 0 && (
                <nav aria-label="Aisles" className="flex flex-col gap-1">
                    <span className="mb-1 text-sm font-semibold">Aisles</span>
                    {departments.map((department) => (
                        <Link
                            key={department.id}
                            href={department.url}
                            aria-current={department.id === currentDepartment ? 'page' : undefined}
                            className={cn(
                                'hover:bg-brand-soft flex min-h-10 items-center justify-between gap-3 rounded-lg px-2.5 text-sm transition-colors',
                                department.id === currentDepartment && 'bg-brand-soft text-brand-text font-bold',
                            )}
                        >
                            <span>{department.name}</span>
                            <span className="text-muted text-sm tabular-nums">{department.count}</span>
                        </Link>
                    ))}
                </nav>
            )}
        </div>
    );
}

function Pages({ pagination, query }: { pagination: Pagination; query: Query }) {
    const path = usePath();

    if (pagination.lastPage <= 1) {
        return null;
    }

    const href = (page: number) => {
        const params = new URLSearchParams({ ...query, ...(page > 1 ? { page: String(page) } : {}) });
        const search = params.toString();

        return search ? `${path}?${search}` : path;
    };
    const { page, lastPage } = pagination;
    const pages = Array.from(new Set([1, page - 1, page, page + 1, lastPage])).filter((p) => p >= 1 && p <= lastPage);
    const item =
        'flex h-10 min-w-10 items-center justify-center rounded-full border border-line bg-white px-3 text-sm font-medium text-ink transition-colors hover:bg-brand-soft';

    return (
        <nav aria-label="Pages" className="flex flex-wrap items-center justify-center gap-2">
            {page > 1 && (
                <Link href={href(page - 1)} className={item} preserveScroll={false}>
                    <ChevronLeft aria-hidden="true" className="size-5" />
                    <span className="sr-only sm:not-sr-only sm:ml-1">Previous</span>
                </Link>
            )}
            {pages.map((p, i) => (
                <span key={p} className="flex items-center gap-2">
                    {i > 0 && p - pages[i - 1] > 1 && (
                        <span aria-hidden="true" className="text-muted px-1">
                            …
                        </span>
                    )}
                    <Link
                        href={href(p)}
                        aria-current={p === page ? 'page' : undefined}
                        aria-label={`Page ${p}`}
                        className={cn(item, p === page && 'bg-brand text-on-brand hover:bg-brand border-transparent')}
                    >
                        {p}
                    </Link>
                </span>
            ))}
            {page < lastPage && (
                <Link href={href(page + 1)} className={item}>
                    <span className="sr-only sm:not-sr-only sm:mr-1">Next</span>
                    <ChevronRight aria-hidden="true" className="size-5" />
                </Link>
            )}
        </nav>
    );
}
