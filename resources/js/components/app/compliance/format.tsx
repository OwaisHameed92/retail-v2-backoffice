import { StatusBadge, type StatusToneMap } from '@/components/shared/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { type BreakdownRow, type ExpiryStatus, type Schedule } from './types';

export { formatDateTime, formatDay } from '@/components/app/pricing/format';
export { number } from '@/components/shared/trading/format';

export const dash = <span className="text-muted-foreground">—</span>;

const EXPIRY_TONES: StatusToneMap = { valid: 'success', expiring: 'warning', expired: 'danger', none: 'neutral' };
const EXPIRY_LABELS: Record<ExpiryStatus, string> = { valid: 'In date', expiring: 'Expiring soon', expired: 'Expired', none: 'No expiry' };

/** In date / expiring soon / expired / no expiry, with the days left when it matters. */
export function ExpiryBadge({ status, daysLeft }: { status: ExpiryStatus; daysLeft: number | null }) {
    const suffix = status === 'expiring' && daysLeft !== null ? (daysLeft === 0 ? ' · today' : ` · ${daysLeft}d`) : '';

    return <StatusBadge status={status} tones={EXPIRY_TONES} label={`${EXPIRY_LABELS[status]}${suffix}`} />;
}

export const EXPIRY_FILTERS = [
    { value: 'expired', label: 'Expired' },
    { value: 'expiring', label: 'Expiring soon' },
    { value: 'valid', label: 'In date' },
];

export const SCHEDULE_LABELS: Record<string, string> = { daily: 'Daily', weekly: 'Weekly', perShift: 'Every shift' };

export const scheduleLabel = (s: Schedule) => (s ? (SCHEDULE_LABELS[s] ?? s) : 'Unknown');

/** A refusal rate: "4.2%", amber from 5%, "—" when nothing was checked. */
export function Rate({ value, className }: { value: string | null; className?: string }) {
    if (value === null) {
        return dash;
    }

    return <span className={cn('tabular-nums', Number(value) >= 5 && 'text-warning-foreground font-medium', className)}>{value}%</span>;
}

/** Checks, refusals and rate for one dimension (shop, staff, rule, product). */
export function BreakdownTable({ rows, label, empty }: { rows: BreakdownRow[]; label: string; empty: string }) {
    if (rows.length === 0) {
        return <p className="text-muted-foreground px-5 py-6 text-sm">{empty}</p>;
    }

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead className="pl-5">{label}</TableHead>
                    <TableHead className="text-right">Checks</TableHead>
                    <TableHead className="text-right">Refusals</TableHead>
                    <TableHead className="pr-5 text-right">Refusal rate</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.map((r) => (
                    <TableRow key={r.key ?? r.label}>
                        <TableCell className="max-w-56 truncate pl-5 font-medium">{r.label}</TableCell>
                        <TableCell className="text-right tabular-nums">{r.checks.toLocaleString('en-GB')}</TableCell>
                        <TableCell className="text-right tabular-nums">{r.refusals.toLocaleString('en-GB')}</TableCell>
                        <TableCell className="pr-5 text-right">
                            <Rate value={r.rate} />
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

/** Two stacked lines: main text and a muted subline. */
export function Stack({ main, sub }: { main: string | null; sub?: string | null }) {
    return (
        <div className="grid max-w-56 leading-5">
            <span className="truncate">{main ?? '—'}</span>
            {sub && <span className="text-muted-foreground truncate text-xs">{sub}</span>}
        </div>
    );
}
