import { EmptyState } from '@/components/shared/empty-state';
import { MobileCardList, type MobileCardContent, type MobileCardField } from '@/components/shared/mobile-card-list';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { flexRender, getCoreRowModel, useReactTable, type Column, type ColumnDef, type Row, type SortingState } from '@tanstack/react-table';
import { ArrowDown, ArrowUp, ChevronsUpDown, SearchX } from 'lucide-react';
import { useEffect, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { DataTablePagination } from './data-table-pagination';
import { DataTableToolbar } from './data-table-toolbar';
import { type MobileSlot, type TableMeta, type TableParams } from './types';
import { useTableQuery } from './use-table-query';

export interface DataTableProps<TData, TValue = unknown> {
    /**
     * TanStack column defs. Sorting is off by default; set `enableSorting: true` on columns the server whitelists.
     * `meta: { align: 'right' }` for numbers/money; `meta: { mobile: 'title' | 'aside' | 'field' | 'actions' | 'hidden' }`
     * to control the phone card.
     */
    columns: ColumnDef<TData, TValue>[];
    data: TData[];
    meta: TableMeta;
    /** Called with changed params. Defaults to an Inertia `router.get` that keeps state (see useTableQuery). */
    onChange?: (params: TableParams) => void;
    /** Inertia partial reload keys for the default onChange, e.g. ['products']. */
    only?: string[];
    /** Show the search box. */
    searchable?: boolean;
    searchPlaceholder?: string;
    /** Filter controls for the toolbar. Use useTableQuery().update in them. */
    filters?: ReactNode;
    toolbarActions?: ReactNode;
    onRowClick?: (row: TData) => void;
    getRowId?: (row: TData, index: number) => string;
    /** Shown when there are no rows. Defaults to a "nothing found" message. */
    empty?: ReactNode;
    /** Force the skeleton, e.g. while a deferred prop loads. */
    loading?: boolean;
    /** Phone layout: cards (default) or the plain table scrolling sideways. */
    mobile?: 'cards' | 'table';
    /** Custom phone card; defaults to one built from the columns. */
    renderMobileCard?: (row: TData) => MobileCardContent;
    className?: string;
}

function headerLabel<TData, TValue>(column: Column<TData, TValue>): string {
    const header = column.columnDef.header;
    if (column.columnDef.meta?.label) {
        return column.columnDef.meta.label;
    }
    if (typeof header === 'string') {
        return header;
    }
    const id = column.id.replace(/_/g, ' ');

    return id.charAt(0).toUpperCase() + id.slice(1);
}

function mobileSlot<TData, TValue>(column: Column<TData, TValue>, index: number): MobileSlot {
    if (column.columnDef.meta?.mobile) {
        return column.columnDef.meta.mobile;
    }
    if (index === 0) {
        return 'title';
    }
    if (column.id === 'status') {
        return 'aside';
    }
    if (column.id === 'actions') {
        return 'actions';
    }

    return 'field';
}

const alignClass = { left: 'text-left', right: 'text-right', center: 'text-center' } as const;

/** Keeps the header sticky when the table fits; falls back to sideways scrolling when it does not. */
function useFitsContainer() {
    const containerRef = useRef<HTMLDivElement>(null);
    const [fits, setFits] = useState(true);

    useEffect(() => {
        const container = containerRef.current;
        const table = container?.querySelector('table');
        if (!container || !table || typeof ResizeObserver === 'undefined') {
            return;
        }
        const check = () => setFits(table.scrollWidth <= container.clientWidth + 1);
        const observer = new ResizeObserver(check);
        observer.observe(container);
        observer.observe(table);
        check();

        return () => observer.disconnect();
    }, []);

    return { containerRef, fits };
}

/**
 * The list screen table: toolbar (search, filters), a card with a sticky-header table (cards on phones),
 * sortable headers, skeleton while loading, empty state, and pagination in the card footer.
 */
export function DataTable<TData, TValue = unknown>({
    columns,
    data,
    meta,
    onChange,
    only,
    searchable = true,
    searchPlaceholder,
    filters,
    toolbarActions,
    onRowClick,
    getRowId,
    empty,
    loading: loadingProp = false,
    mobile = 'cards',
    renderMobileCard,
    className,
}: DataTableProps<TData, TValue>) {
    const query = useTableQuery({ only });
    const change = onChange ?? query.update;
    const loading = loadingProp || query.loading;
    const { containerRef, fits } = useFitsContainer();

    const sorting: SortingState = meta.sort ? [{ id: meta.sort, desc: meta.direction === 'desc' }] : [];

    const table = useReactTable({
        data,
        columns,
        getRowId,
        getCoreRowModel: getCoreRowModel(),
        manualPagination: true,
        manualSorting: true,
        manualFiltering: true,
        enableSortingRemoval: true,
        defaultColumn: { enableSorting: false },
        pageCount: Math.max(1, Math.ceil(meta.total / meta.perPage)),
        state: {
            sorting,
            pagination: { pageIndex: meta.page - 1, pageSize: meta.perPage },
        },
        onSortingChange: (updater) => {
            const next = typeof updater === 'function' ? updater(sorting) : updater;
            const first = next[0];
            change({ sort: first?.id, direction: first ? (first.desc ? 'desc' : 'asc') : undefined, page: 1 });
        },
    });

    const rows = table.getRowModel().rows;
    const leafColumns = table.getVisibleLeafColumns();
    const columnCount = leafColumns.length;
    const skeletonRows = Math.min(meta.perPage, Math.max(rows.length, 5));
    const useCards = mobile === 'cards';

    const rowKeyDown = (event: KeyboardEvent<HTMLTableRowElement>, row: TData) => {
        if (event.target !== event.currentTarget) {
            return;
        }
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            onRowClick?.(row);
        }
    };

    const autoCard = (row: Row<TData>): MobileCardContent => {
        const content: MobileCardContent = { title: null };
        const fields: MobileCardField[] = [];
        row.getVisibleCells().forEach((cell, index) => {
            const rendered = flexRender(cell.column.columnDef.cell, cell.getContext());
            const slot = mobileSlot(cell.column, index);
            if (slot === 'title') content.title = rendered;
            else if (slot === 'aside') content.aside = rendered;
            else if (slot === 'actions') content.actions = rendered;
            else if (slot === 'field' && fields.length < 4) fields.push({ label: headerLabel(cell.column), value: rendered });
        });
        content.fields = fields;

        return content;
    };

    const emptyContent = empty ?? (
        <EmptyState
            icon={SearchX}
            title="Nothing found"
            body={meta.search ? 'Try a different search or clear the filters.' : 'There is nothing here yet.'}
        />
    );

    return (
        <div className={cn('flex flex-col gap-3', className)}>
            <DataTableToolbar
                search={meta.search}
                onSearch={searchable ? (search) => change({ search, page: 1 }) : undefined}
                searchPlaceholder={searchPlaceholder}
                filters={filters}
                actions={toolbarActions}
            />

            <Card className="overflow-clip p-0" aria-busy={loading}>
                <div className={cn(useCards && 'hidden md:block')}>
                    <Table
                        containerClassName={cn(fits ? 'overflow-x-visible' : 'overflow-x-auto')}
                        ref={(node) => {
                            containerRef.current = (node?.parentElement as HTMLDivElement | null) ?? null;
                        }}
                    >
                        <TableHeader className={cn(fits && 'sticky top-[var(--app-header-height,0px)] z-10')}>
                            {table.getHeaderGroups().map((headerGroup) => (
                                <TableRow key={headerGroup.id} className="hover:bg-transparent">
                                    {headerGroup.headers.map((header) => {
                                        const content = header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext());
                                        const sorted = header.column.getIsSorted();
                                        const columnMeta = header.column.columnDef.meta;
                                        const align = columnMeta?.align ?? 'left';

                                        return (
                                            <TableHead
                                                key={header.id}
                                                aria-sort={sorted === 'asc' ? 'ascending' : sorted === 'desc' ? 'descending' : undefined}
                                                className={cn(alignClass[align], columnMeta?.headerClassName)}
                                            >
                                                {header.column.getCanSort() ? (
                                                    <button
                                                        type="button"
                                                        onClick={header.column.getToggleSortingHandler()}
                                                        className={cn(
                                                            'hover:text-foreground focus-visible:ring-ring/40 -mx-1 inline-flex items-center gap-1 rounded px-1 py-0.5 uppercase transition-colors outline-none focus-visible:ring-2',
                                                            sorted && 'text-foreground',
                                                            align === 'right' && 'flex-row-reverse',
                                                        )}
                                                    >
                                                        {content}
                                                        {sorted === 'asc' ? (
                                                            <ArrowUp className="size-3" aria-hidden />
                                                        ) : sorted === 'desc' ? (
                                                            <ArrowDown className="size-3" aria-hidden />
                                                        ) : (
                                                            <ChevronsUpDown className="size-3 opacity-40" aria-hidden />
                                                        )}
                                                    </button>
                                                ) : (
                                                    content
                                                )}
                                            </TableHead>
                                        );
                                    })}
                                </TableRow>
                            ))}
                        </TableHeader>
                        <TableBody>
                            {loading ? (
                                Array.from({ length: skeletonRows }).map((_, index) => (
                                    <TableRow key={`skeleton-${index}`} className="hover:bg-transparent">
                                        {leafColumns.map((column, cell) => (
                                            <TableCell key={column.id} className={cn(alignClass[column.columnDef.meta?.align ?? 'left'])}>
                                                {cell === 0 ? (
                                                    <div className="flex items-center gap-3">
                                                        <Skeleton className="size-8 rounded-full" />
                                                        <div className="grid gap-1.5">
                                                            <Skeleton className="h-3.5 w-32" />
                                                            <Skeleton className="h-3 w-20" />
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <Skeleton
                                                        className={cn(
                                                            'inline-block h-3.5 w-full max-w-24',
                                                            column.columnDef.meta?.align === 'right' && 'max-w-16',
                                                        )}
                                                    />
                                                )}
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                ))
                            ) : rows.length === 0 ? (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell colSpan={columnCount} className="h-auto p-0">
                                        {emptyContent}
                                    </TableCell>
                                </TableRow>
                            ) : (
                                rows.map((row) => (
                                    <TableRow
                                        key={row.id}
                                        onClick={onRowClick ? () => onRowClick(row.original) : undefined}
                                        onKeyDown={onRowClick ? (event) => rowKeyDown(event, row.original) : undefined}
                                        tabIndex={onRowClick ? 0 : undefined}
                                        className={cn(onRowClick && 'focus-visible:bg-muted/60 cursor-pointer outline-none')}
                                    >
                                        {row.getVisibleCells().map((cell) => {
                                            const columnMeta = cell.column.columnDef.meta;

                                            return (
                                                <TableCell
                                                    key={cell.id}
                                                    className={cn(alignClass[columnMeta?.align ?? 'left'], columnMeta?.cellClassName)}
                                                >
                                                    {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                                </TableCell>
                                            );
                                        })}
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>

                {useCards && (
                    <div className="md:hidden">
                        {loading ? (
                            <ul className="divide-y">
                                {Array.from({ length: Math.min(skeletonRows, 6) }).map((_, index) => (
                                    <li key={index} className="flex flex-col gap-3 px-4 py-3.5">
                                        <div className="flex items-center gap-3">
                                            <Skeleton className="size-8 rounded-full" />
                                            <div className="grid flex-1 gap-1.5">
                                                <Skeleton className="h-3.5 w-40" />
                                                <Skeleton className="h-3 w-24" />
                                            </div>
                                            <Skeleton className="h-5 w-14 rounded-full" />
                                        </div>
                                        <div className="grid grid-cols-2 gap-3">
                                            <Skeleton className="h-3 w-24" />
                                            <Skeleton className="h-3 w-20" />
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        ) : rows.length === 0 ? (
                            emptyContent
                        ) : (
                            <MobileCardList
                                items={rows}
                                getKey={(row) => row.id}
                                render={(row) => (renderMobileCard ? renderMobileCard(row.original) : autoCard(row))}
                                onItemClick={onRowClick ? (row) => onRowClick(row.original) : undefined}
                            />
                        )}
                    </div>
                )}

                {meta.total > 0 && (
                    <DataTablePagination
                        className="bg-subtle border-t px-4 py-2.5"
                        meta={meta}
                        disabled={loading}
                        onPageChange={(page) => change({ page })}
                        onPerPageChange={(perPage) => change({ perPage, page: 1 })}
                    />
                )}
            </Card>
        </div>
    );
}

export default DataTable;
