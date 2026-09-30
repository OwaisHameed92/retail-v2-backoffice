import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { router } from '@inertiajs/react';
import { ChevronDown, Download, FileSpreadsheet, Loader2, TriangleAlert } from 'lucide-react';
import { useEffect } from 'react';
import { formatDateTime, formatDay, number } from './format';
import { type SalesExportRow } from './types';

interface Props {
    exports: SalesExportRow[];
    /** The CSV link for the current filters. */
    href: string;
    count: number;
    capped: boolean;
    streamLimit: number;
}

/**
 * "Export CSV" of the filtered list: a direct download up to `streamLimit` sales, otherwise a queued file that shows
 * here when ready (the list reloads its exports every few seconds while one is being built).
 */
export function ExportMenu({ exports, href, count, capped, streamLimit }: Props) {
    const pending = exports.some((e) => e.status === 'queued' || e.status === 'running');

    useEffect(() => {
        if (!pending) {
            return;
        }
        const timer = window.setInterval(() => router.reload({ only: ['exports'] }), 4000);

        return () => window.clearInterval(timer);
    }, [pending]);

    const big = capped || count > streamLimit;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline">
                    {pending ? <Loader2 className="animate-spin" /> : <Download />}
                    Export
                    <ChevronDown className="opacity-60" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80">
                <DropdownMenuItem asChild disabled={count === 0}>
                    <a href={href}>
                        <FileSpreadsheet />
                        <span className="grid">
                            <span>Export these sales as CSV</span>
                            <span className="text-muted-foreground text-xs">
                                {count === 0
                                    ? 'Nothing to export with these filters'
                                    : big
                                      ? 'A large export: we prepare it and list it below'
                                      : `${number(count)} ${count === 1 ? 'sale' : 'sales'}, downloads now`}
                            </span>
                        </span>
                    </a>
                </DropdownMenuItem>
                {exports.length > 0 && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel className="text-muted-foreground text-xs font-medium">Your recent exports (kept 7 days)</DropdownMenuLabel>
                        {exports.map((e) => (
                            <ExportItem key={e.id} item={e} />
                        ))}
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function ExportItem({ item }: { item: SalesExportRow }) {
    const range = item.from && item.to ? (item.from === item.to ? formatDay(item.from) : `${formatDay(item.from)} – ${formatDay(item.to)}`) : 'Sales';
    const detail =
        item.status === 'ready'
            ? `${number(item.rows)} sales · ${formatDateTime(item.createdAt)}`
            : item.status === 'failed'
              ? 'Could not be built. Try again.'
              : 'Preparing…';
    const icon =
        item.status === 'failed' ? (
            <TriangleAlert className="text-destructive" />
        ) : item.downloadable ? (
            <Download />
        ) : (
            <Loader2 className="animate-spin" />
        );
    const body = (
        <>
            {icon}
            <span className="grid min-w-0">
                <span className="truncate">{range}</span>
                <span className="text-muted-foreground text-xs">{detail}</span>
            </span>
        </>
    );

    return item.downloadable ? (
        <DropdownMenuItem asChild>
            <a href={route('app.sales.exports.download', item.id)}>{body}</a>
        </DropdownMenuItem>
    ) : (
        <DropdownMenuItem disabled>{body}</DropdownMenuItem>
    );
}
