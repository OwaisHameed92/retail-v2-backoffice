import { EmptyState } from '@/components/shared/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { flexRender, getCoreRowModel, useReactTable, type ColumnDef, type SortingState } from '@tanstack/react-table';
import { ArrowDown, ArrowUp, ChevronsUpDown, SearchX } from 'lucide-react';
import { type KeyboardEvent, type ReactNode } from 'react';
import { DataTablePagination } from './data-table-pagination';
import { DataTableToolbar } from './data-table-toolbar';
import { type TableMeta, type TableParams } from './types';
import { useTableQuery } from './use-table-query';

export interface DataTableProps<TData, TValue = unknown> {
    /** TanStack column defs. Sorting is off by default; set `enableSorting: true` on columns the server whitelists. */
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
    className?: string;
}

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
    className,
}: DataTableProps<TData, TValue>) {
    const query = useTableQuery({ only });
    const change = onChange ?? query.update;
    const loading = loadingProp || query.loading;

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
    const columnCount = table.getVisibleLeafColumns().length;
    const skeletonRows = Math.min(meta.perPage, Math.max(rows.length, 5));

    const rowKeyDown = (event: KeyboardEvent<HTMLTableRowElement>, row: TData) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            onRowClick?.(row);
        }
    };

    return (
        <div className={cn('flex flex-col gap-3', className)}>
            <DataTableToolbar
                search={meta.search}
                onSearch={searchable ? (search) => change({ search, page: 1 }) : undefined}
                searchPlaceholder={searchPlaceholder}
                filters={filters}
                actions={toolbarActions}
            />

            <div className="overflow-hidden rounded-lg border bg-card" aria-busy={loading}>
                <Table>
                    <TableHeader>
                        {table.getHeaderGroups().map((headerGroup) => (
                            <TableRow key={headerGroup.id} className="hover:bg-transparent">
                                {headerGroup.headers.map((header) => {
                                    const content = header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext());
                                    const sorted = header.column.getIsSorted();

                                    return (
                                        <TableHead
                                            key={header.id}
                                            aria-sort={sorted === 'asc' ? 'ascending' : sorted === 'desc' ? 'descending' : undefined}
                                        >
                                            {header.column.getCanSort() ? (
                                                <button
                                                    type="button"
                                                    onClick={header.column.getToggleSortingHandler()}
                                                    className="-ml-1 inline-flex items-center gap-1 rounded px-1 py-0.5 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                >
                                                    {content}
                                                    {sorted === 'asc' ? (
                                                        <ArrowUp className="size-3.5" aria-hidden />
                                                    ) : sorted === 'desc' ? (
                                                        <ArrowDown className="size-3.5" aria-hidden />
                                                    ) : (
                                                        <ChevronsUpDown className="size-3.5 opacity-50" aria-hidden />
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
                                    {Array.from({ length: columnCount }).map((__, cell) => (
                                        <TableCell key={cell}>
                                            <Skeleton className="h-4 w-full max-w-40" />
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))
                        ) : rows.length === 0 ? (
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={columnCount} className="p-0">
                                    {empty ?? (
                                        <EmptyState
                                            icon={SearchX}
                                            title="Nothing found"
                                            body={meta.search ? 'Try a different search or clear the filters.' : 'There is nothing here yet.'}
                                        />
                                    )}
                                </TableCell>
                            </TableRow>
                        ) : (
                            rows.map((row) => (
                                <TableRow
                                    key={row.id}
                                    onClick={onRowClick ? () => onRowClick(row.original) : undefined}
                                    onKeyDown={onRowClick ? (event) => rowKeyDown(event, row.original) : undefined}
                                    tabIndex={onRowClick ? 0 : undefined}
                                    className={cn(onRowClick && 'cursor-pointer focus-visible:bg-muted/50 focus-visible:outline-none')}
                                >
                                    {row.getVisibleCells().map((cell) => (
                                        <TableCell key={cell.id}>{flexRender(cell.column.columnDef.cell, cell.getContext())}</TableCell>
                                    ))}
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </div>

            {meta.total > 0 && (
                <DataTablePagination
                    meta={meta}
                    disabled={loading}
                    onPageChange={(page) => change({ page })}
                    onPerPageChange={(perPage) => change({ perPage, page: 1 })}
                />
            )}
        </div>
    );
}

export default DataTable;
