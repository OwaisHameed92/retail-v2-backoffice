import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { PER_PAGE_OPTIONS, type TableMeta } from './types';

interface DataTablePaginationProps {
    meta: TableMeta;
    onPageChange: (page: number) => void;
    onPerPageChange: (perPage: number) => void;
    disabled?: boolean;
}

const number = new Intl.NumberFormat('en-GB');

export function DataTablePagination({ meta, onPageChange, onPerPageChange, disabled = false }: DataTablePaginationProps) {
    const lastPage = meta.lastPage ?? Math.max(1, Math.ceil(meta.total / meta.perPage));
    const from = meta.total === 0 ? 0 : (meta.page - 1) * meta.perPage + 1;
    const to = Math.min(meta.page * meta.perPage, meta.total);

    return (
        <div className="flex flex-col-reverse gap-3 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
            <p className="tabular-nums">
                {meta.total === 0 ? 'No results' : `Showing ${number.format(from)}–${number.format(to)} of ${number.format(meta.total)}`}
            </p>
            <div className="flex items-center justify-between gap-4 sm:justify-end">
                <div className="flex items-center gap-2">
                    <span className="hidden sm:inline">Rows per page</span>
                    <Select value={String(meta.perPage)} onValueChange={(value) => onPerPageChange(Number(value))} disabled={disabled}>
                        <SelectTrigger className="h-8 w-[4.5rem]" aria-label="Rows per page">
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
    );
}

export default DataTablePagination;
