import { daysUntil, formatDate, formatDateTimeShort, formatRelative } from '@/components/admin/licences/format';
import { LicenceStatusBadge } from '@/components/admin/licences/licence-status-badge';
import { type LicenceRow } from '@/components/admin/licences/types';
import { cn } from '@/lib/utils';
import { type ColumnDef } from '@tanstack/react-table';
import { MonitorSmartphone } from 'lucide-react';

export function MaskedKey({ value, className }: { value: string; className?: string }) {
    const last4 = value.slice(-4);

    return (
        <span className={cn('font-mono text-sm whitespace-nowrap', className)} aria-label={`Key ending ${last4.split('').join(' ')}`}>
            <span className="text-muted-foreground">{value.slice(0, -4)}</span>
            <span className="font-semibold">{last4}</span>
        </span>
    );
}

/** "Trial ends 24 Sept 2026 · in 5 days", "Expires …", "Starts on activation". */
export function EndDate({ row }: { row: Pick<LicenceRow, 'status' | 'endsAt' | 'isTrial' | 'expiresAt' | 'graceEndsAt'> }) {
    if (row.status === 'revoked') {
        return <span className="text-muted-foreground">—</span>;
    }
    if (!row.endsAt) {
        return <span className="text-muted-foreground">On activation</span>;
    }

    const days = daysUntil(row.endsAt);
    const soon = days !== null && days >= 0 && days <= 3;
    const label = row.isTrial ? 'Trial ends' : row.expiresAt ? 'Paid until' : 'Ends';

    return (
        <div className="leading-tight whitespace-nowrap">
            <div className="text-muted-foreground text-xs">{label}</div>
            <div className={cn('tabular-nums', (soon || row.status === 'grace') && 'text-warning-foreground font-medium', row.status === 'expired' && 'text-destructive')}>
                {formatDate(row.endsAt)}
            </div>
            <div className="text-muted-foreground text-xs">{formatRelative(row.status === 'grace' ? row.graceEndsAt : row.endsAt)}{row.status === 'grace' ? ' till locks' : ''}</div>
        </div>
    );
}

export function Device({ row }: { row: Pick<LicenceRow, 'deviceName' | 'deviceId' | 'lastAppVersion'> }) {
    if (!row.deviceId) {
        return <span className="text-muted-foreground">Not activated</span>;
    }

    return (
        <div className="min-w-0 leading-tight">
            <div className="flex items-center gap-1.5 truncate">
                <MonitorSmartphone className="text-muted-foreground size-3.5 shrink-0" aria-hidden />
                <span className="truncate">{row.deviceName ?? 'Unnamed PC'}</span>
            </div>
            <div className="text-muted-foreground truncate font-mono text-xs" title={row.deviceId}>
                {row.deviceId}
            </div>
        </div>
    );
}

export function CheckIn({ at }: { at: string | null }) {
    if (!at) {
        return <span className="text-muted-foreground">Never</span>;
    }

    return (
        <time dateTime={at} title={formatDateTimeShort(at)} className="whitespace-nowrap tabular-nums">
            {formatRelative(at)}
        </time>
    );
}

/**
 * Columns of the admin licence list for a breakpoint (see useBreakpoint): narrow screens get fewer columns, with
 * the rest stacked in the key cell, instead of hidden cells. Sortable ids match LicenceQuery::SORTABLE.
 */
export function licenceColumns({ showBusiness = true, breakpoint = 4 }: { showBusiness?: boolean; breakpoint?: number } = {}): ColumnDef<LicenceRow>[] {
    const wide = breakpoint >= 2;
    const columns: ColumnDef<LicenceRow>[] = [
        {
            id: 'key',
            header: 'Key',
            cell: ({ row }) => (
                <div className="min-w-0">
                    <MaskedKey value={row.original.maskedKey} />
                    {!wide && (
                        <div className="text-muted-foreground mt-0.5 truncate text-xs">
                            {showBusiness && `${row.original.company.name} · `}
                            {row.original.register.name}, {row.original.branch.name}
                        </div>
                    )}
                    {breakpoint === 0 && (
                        <div className="mt-1.5 flex flex-wrap items-center gap-2">
                            <LicenceStatusBadge status={row.original.status} reason={row.original.statusReason} />
                            <span className="text-muted-foreground text-xs">
                                {row.original.endsAt ? `${row.original.isTrial ? 'Trial ends' : 'Until'} ${formatDate(row.original.endsAt)}` : 'Not activated'}
                            </span>
                        </div>
                    )}
                </div>
            ),
        },
    ];

    if (wide && showBusiness) {
        columns.push({
            id: 'company_name',
            accessorFn: (row) => row.company.name,
            header: 'Business',
            enableSorting: true,
            cell: ({ row }) => <span className="block max-w-48 truncate font-medium">{row.original.company.name}</span>,
        });
    }

    if (wide) {
        columns.push({
            id: 'register_name',
            accessorFn: (row) => row.register.name,
            header: 'Till',
            enableSorting: true,
            cell: ({ row }) => (
                <div className="min-w-0 leading-tight">
                    <div className="truncate">
                        {row.original.register.name}
                        {row.original.register.code && <span className="text-muted-foreground font-mono text-xs"> · {row.original.register.code}</span>}
                    </div>
                    <div className="text-muted-foreground truncate text-xs">{row.original.branch.name}</div>
                </div>
            ),
        });
    }

    if (breakpoint >= 3) {
        columns.push({
            id: 'plan_name',
            accessorFn: (row) => row.plan?.name ?? '',
            header: 'Plan',
            enableSorting: true,
            cell: ({ row }) => row.original.plan?.name ?? '—',
        });
    }

    if (breakpoint >= 1) {
        columns.push(
            {
                id: 'status',
                header: 'Status',
                cell: ({ row }) => <LicenceStatusBadge status={row.original.status} reason={row.original.statusReason} />,
            },
            {
                id: 'ends_at',
                accessorFn: (row) => row.endsAt ?? '',
                header: 'Expires',
                enableSorting: true,
                cell: ({ row }) => <EndDate row={row.original} />,
            },
        );
    }

    if (breakpoint >= 4) {
        columns.push({
            id: 'device',
            header: 'PC',
            cell: ({ row }) => (
                <div className="max-w-44">
                    <Device row={row.original} />
                </div>
            ),
        });
    }

    if (breakpoint >= 3) {
        columns.push({
            id: 'last_check_in_at',
            accessorFn: (row) => row.lastCheckInAt ?? '',
            header: 'Last check-in',
            enableSorting: true,
            cell: ({ row }) => <CheckIn at={row.original.lastCheckInAt} />,
        });
    }

    return columns;
}
