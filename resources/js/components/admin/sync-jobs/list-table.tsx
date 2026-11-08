import { MobileCardList } from '@/components/shared/mobile-card-list';
import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

export interface ListColumn<T> {
    key: string;
    header: string;
    cell: (row: T) => ReactNode;
    align?: 'left' | 'right';
    /** Phone card slot: the first column is the title unless another says so. */
    mobile?: 'title' | 'aside' | 'field' | 'hidden';
    className?: string;
}

interface ListTableProps<T> {
    columns: ListColumn<T>[];
    rows: T[];
    getKey: (row: T) => string;
    /** Accessible table name. */
    label: string;
    rowClassName?: (row: T) => string | undefined;
}

/**
 * A short read-only list inside a SectionCard (flush): a plain table from `md`, cards on phones. For the small,
 * unpaged lists of the Sync & jobs page; long lists use DataTable.
 */
export function ListTable<T>({ columns, rows, getKey, label, rowClassName }: ListTableProps<T>) {
    const slot = (column: ListColumn<T>, index: number) => column.mobile ?? (index === 0 ? 'title' : 'field');
    const title = columns.find((column, index) => slot(column, index) === 'title');
    const aside = columns.find((column, index) => slot(column, index) === 'aside');
    const fields = columns.filter((column, index) => slot(column, index) === 'field');

    return (
        <>
            <div className="hidden overflow-x-auto md:block">
                <table className="w-full text-sm" aria-label={label}>
                    <thead>
                        <tr className="text-muted-foreground border-b text-left text-xs">
                            {columns.map((column, index) => (
                                <th
                                    key={column.key}
                                    scope="col"
                                    className={cn(
                                        'py-2.5 font-medium whitespace-nowrap',
                                        index === 0 ? 'pr-3 pl-5 sm:pl-6' : index === columns.length - 1 ? 'pr-5 pl-3 sm:pr-6' : 'px-3',
                                        column.align === 'right' && 'text-right',
                                    )}
                                >
                                    {column.header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {rows.map((row) => (
                            <tr key={getKey(row)} className={cn('hover:bg-muted/40 align-top transition-colors', rowClassName?.(row))}>
                                {columns.map((column, index) => (
                                    <td
                                        key={column.key}
                                        className={cn(
                                            'py-3',
                                            index === 0 ? 'pr-3 pl-5 sm:pl-6' : index === columns.length - 1 ? 'pr-5 pl-3 sm:pr-6' : 'px-3',
                                            column.align === 'right' && 'text-right tabular-nums',
                                            column.className,
                                        )}
                                    >
                                        {column.cell(row)}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <MobileCardList
                className="md:hidden"
                items={rows}
                getKey={(row) => getKey(row)}
                render={(row) => ({
                    title: title?.cell(row),
                    aside: aside?.cell(row),
                    fields: fields.map((column) => ({ label: column.header, value: column.cell(row) })),
                })}
            />
        </>
    );
}
