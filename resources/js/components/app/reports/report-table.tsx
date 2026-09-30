import { Cell, isNumeric } from '@/components/app/reports/format';
import { type ReportTableData } from '@/components/app/reports/types';
import { SectionCard } from '@/components/shared/section-card';
import { number } from '@/components/shared/trading/format';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { ChevronLeft, ChevronRight } from 'lucide-react';

interface ReportTableCardProps {
    table: ReportTableData;
    onPage?: (page: number) => void;
}

/**
 * One report table: numbers right-aligned in tabular figures, the totals row in the footer, and paging for long
 * lists (stock, products, shifts). Wide tables scroll inside the card, never the page.
 */
export function ReportTableCard({ table, onPage }: ReportTableCardProps) {
    const { pagination } = table;
    const first = pagination ? (pagination.page - 1) * pagination.perPage + 1 : 0;
    const last = pagination ? Math.min(pagination.total, pagination.page * pagination.perPage) : 0;

    return (
        <SectionCard
            title={table.title}
            description={table.description ?? undefined}
            flush
            footer={
                pagination && pagination.lastPage > 1 && onPage ? (
                    <div className="flex items-center justify-between gap-3 text-sm">
                        <span className="text-muted-foreground">
                            {number(first)}–{number(last)} of {number(pagination.total)}
                        </span>
                        <div className="flex items-center gap-2">
                            <Button variant="outline" size="sm" disabled={pagination.page <= 1} onClick={() => onPage(pagination.page - 1)}>
                                <ChevronLeft className="size-4" aria-hidden />
                                Previous
                            </Button>
                            <Button variant="outline" size="sm" disabled={pagination.page >= pagination.lastPage} onClick={() => onPage(pagination.page + 1)}>
                                Next
                                <ChevronRight className="size-4" aria-hidden />
                            </Button>
                        </div>
                    </div>
                ) : undefined
            }
        >
            {table.rows.length === 0 ? (
                <p className="text-muted-foreground px-5 py-10 text-center text-sm">{table.empty}</p>
            ) : (
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                {table.columns.map((column) => (
                                    <TableHead key={column.key} className={cn('whitespace-nowrap', isNumeric(column.type) && 'text-right')}>
                                        {column.label}
                                    </TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {table.rows.map((row, index) => (
                                <TableRow key={String(row.id ?? index)}>
                                    {table.columns.map((column, c) => (
                                        <TableCell
                                            key={column.key}
                                            className={cn('whitespace-nowrap', isNumeric(column.type) && 'text-right', c === 0 && 'text-foreground font-medium')}
                                        >
                                            <Cell value={row[column.key] ?? null} type={column.type} />
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))}
                        </TableBody>
                        {table.totals && (
                            <TableFooter>
                                <TableRow>
                                    {table.columns.map((column) => (
                                        <TableCell key={column.key} className={cn('font-semibold whitespace-nowrap', isNumeric(column.type) && 'text-right')}>
                                            {column.key in (table.totals ?? {}) ? <Cell value={table.totals?.[column.key] ?? null} type={column.type} /> : null}
                                        </TableCell>
                                    ))}
                                </TableRow>
                            </TableFooter>
                        )}
                    </Table>
                </div>
            )}
        </SectionCard>
    );
}
