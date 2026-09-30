import { type CellValue, type ColumnType, type ReportFilters } from '@/components/app/reports/types';
import { StatusBadge } from '@/components/shared/status-badge';
import { money, number } from '@/components/shared/trading/format';
import { cn } from '@/lib/utils';
import { TriangleAlert } from 'lucide-react';
import { type ReactNode } from 'react';

const dateTime = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Europe/London' });
const date = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
const qty = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 3 });

/** Right-aligned column types (numbers). */
export function isNumeric(type: ColumnType): boolean {
    return ['money', 'signedMoney', 'qty', 'count', 'percent'].includes(type);
}

/** A cell as text (print and screen): "£1,234.56", "12.5%", "3 Sept 2026 17:05" (London). Null → "—". */
export function cellText(value: CellValue, type: ColumnType): string {
    if (value === null || value === '') {
        return '—';
    }

    switch (type) {
        case 'money':
        case 'signedMoney':
            return money(String(value));
        case 'qty':
            return qty.format(Number(value));
        case 'count':
            return number(Number(value));
        case 'percent':
            return `${Number(value).toLocaleString('en-GB', { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
        case 'datetime':
            return dateTime.format(new Date(String(value)));
        case 'date':
            return date.format(new Date(`${value}T00:00:00Z`));
        case 'flag':
            return value === true ? 'Over threshold' : '';
        case 'status':
            return value === 'out' ? 'Out of stock' : value === 'low' ? 'Low' : 'OK';
        default:
            return String(value);
    }
}

/** A cell for the screen: variances red when short, green when over; stock status and alert flags as badges. */
export function Cell({ value, type }: { value: CellValue; type: ColumnType }): ReactNode {
    if (type === 'status' && typeof value === 'string') {
        return <StatusBadge status={value} label={cellText(value, type)} tones={{ ok: 'success', low: 'warning', out: 'danger' }} />;
    }

    if (type === 'flag') {
        return value === true ? (
            <span className="text-danger-foreground inline-flex items-center gap-1 text-xs font-medium">
                <TriangleAlert className="size-3.5" aria-hidden />
                Over threshold
            </span>
        ) : null;
    }

    const text = cellText(value, type);

    if (type === 'signedMoney' && value !== null) {
        const n = Number(value);
        return <span className={cn('tabular-nums', n < 0 && 'text-danger-foreground font-medium', n > 0 && 'text-success-foreground')}>{text}</span>;
    }

    return isNumeric(type) ? <span className="tabular-nums">{text}</span> : text;
}

export type ReportQuery = Partial<Record<'period' | 'from' | 'to' | 'compare' | 'till' | 'group' | 'view' | 'page', string>>;

/** The URL query of a report; the period is always sent (reports default to the last 7 days, not today). */
export function reportQuery(filters: ReportFilters, next: Partial<ReportFilters> = {}): ReportQuery {
    const f = { ...filters, ...next };
    const query: ReportQuery = { period: f.period };

    if (f.period === 'custom') {
        query.from = f.from;
        query.to = f.to;
    }
    if (f.compare !== 'previousPeriod') {
        query.compare = f.compare;
    }
    if (f.till) {
        query.till = f.till;
    }
    if (f.group !== 'day') {
        query.group = f.group;
    }
    if (f.view) {
        query.view = f.view;
    }
    if (f.page > 1) {
        query.page = String(f.page);
    }

    return query;
}

/** A report URL with a query (for links, CSV and print). */
export function reportUrl(name: 'app.reports.show' | 'app.reports.export' | 'app.reports.print', report: string, query: ReportQuery): string {
    const params = new URLSearchParams(Object.entries(query).filter((entry): entry is [string, string] => typeof entry[1] === 'string' && entry[1] !== ''));
    const qs = params.toString();

    return `${route(name, report)}${qs ? `?${qs}` : ''}`;
}
