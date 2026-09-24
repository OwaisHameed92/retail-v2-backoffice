import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { PER_PAGE_OPTIONS, type TableMeta } from './types';

interface DataTablePaginationProps {
    meta: TableMeta;
    onPageChange: (page: number) => void;
    onPerPageChange: (perPage: number) => void;
    disabled?: boolean;
    className?: string;
}

const number = new Intl.NumberFormat('en-GB');

/** "1–25 of 132" on the left; rows per page, page x of y and previous/next on the right. */
export function DataTablePagination({ meta, onPageChange, onPerPageChange, disabled = false, className }: DataTablePaginationProps) {
    const lastPage = meta.lastPage ?? Math.max(1, Math.ceil(meta.total / meta.perPage));
    const from = meta.total === 0 ? 0 : (meta.page - 1) * meta.perPage + 1;
    const to = Math.min(meta.page * meta.perPage, meta.total);

    return (
        <div className={cn('text-muted-foreground flex items-center justify-between gap-3 text-[13px]', className)}>
            <p className="tabular-nums">
                {meta.total === 0 ? (
                    'No results'
                ) : (
                    <>
                        <span className="text-foreground font-medium">
                            {number.format(from)}–{number.format(to)}
                        </span>{' '}
                        of <span className="text-foreground font-medium">{number.format(meta.total)}</span>
                    </>
                )}
            </p>
            <div className="flex items-center gap-4">
                <div className="hidden items-center gap-2 sm:flex">
                    <span>Rows per page</span>
                    <Select value={String(meta.perPage)} onValueChange={(value) => onPerPageChange(Number(value))} disabled={disabled}>
                        <SelectTrigger className="h-8 w-[4.25rem] text-[13px]" aria-label="Rows per page">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {PER_PAGE_OPTIONS.map((option) => (
                                <SelectItem key={option} value={String(option)}>
                                    {option}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="flex items-center gap-2">
                    <span className="tabular-nums">
                        Page {meta.page} of {lastPage}
                    </span>
                    <div className="flex items-center gap-1">
                        <Button
                            variant="outline"
                            size="icon"
                            className="size-8"
                            onClick={() => onPageChange(meta.page - 1)}
                            disabled={disabled || meta.page <= 1}
                            aria-label="Previous page"
                        >
                            <ChevronLeft />
                        </Button>
                        <Button
                            variant="outline"
                            size="icon"
                            className="size-8"
                            onClick={() => onPageChange(meta.page + 1)}
                            disabled={disabled || meta.page >= lastPage}
                            aria-label="Next page"
                        >
                            <ChevronRight />
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default DataTablePagination;
