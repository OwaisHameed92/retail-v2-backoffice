import { DeviceStatusBadge, deviceTitle, shopLabel } from '@/components/admin/devices/format';
import { type DeviceRow } from '@/components/admin/devices/types';
import { EntityCell } from '@/components/shared/entity-cell';
import { RowActions } from '@/components/shared/row-actions';
import { StatusPill } from '@/components/shared/status-badge';
import { ago, clockSkewText, shopDateTime } from '@/components/till-health/format';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { type ColumnDef } from '@tanstack/react-table';
import { Building2, KeyRound, Monitor, MonitorX, Smartphone, Star } from 'lucide-react';

/** Columns of the admin Devices list. Sortable ids are the server's sort keys. */
export function deviceColumns({
    canRelease,
    onRelease,
    clockSkewSeconds,
}: {
    canRelease: boolean;
    onRelease: (row: DeviceRow) => void;
    /** Till health's clock skew limit: past it the clock shows in red. */
    clockSkewSeconds: number;
}): ColumnDef<DeviceRow>[] {
    return [
        {
            id: 'company_name',
            header: 'Business',
            enableSorting: true,
            meta: { mobile: 'field', label: 'Business' },
            cell: ({ row: { original: row } }) => <EntityCell name={row.company.name} subline={shopLabel(row)} shape="square" />,
        },
        {
            id: 'name',
            header: 'Device',
            enableSorting: true,
            meta: { mobile: 'title' },
            cell: ({ row: { original: row } }) => (
                <EntityCell
                    name={deviceTitle(row)}
                    subline={row.deviceName ?? (row.kind === 'till' ? 'No PC name yet' : 'Unnamed device')}
                    icon={row.kind === 'till' ? Monitor : Smartphone}
                    shape="square"
                    suffix={
                        row.kind === 'handheld' ? (
                            <StatusPill tone="violet">Handheld</StatusPill>
                        ) : row.isMainTill ? (
                            <Badge variant="info" className="shrink-0">
                                <Star aria-hidden />
                                Main
                            </Badge>
                        ) : (
                            <StatusPill>Extra</StatusPill>
                        )
                    }
                />
            ),
        },
        {
            id: 'app_version',
            header: 'EPOS version',
            cell: ({ row: { original: row } }) =>
                row.appVersion ? (
                    <span className="inline-flex items-center gap-1.5 whitespace-nowrap">
                        <span className="font-mono text-[13px]">{row.appVersion}</span>
                        {row.appOutdated && <StatusPill tone="warning">Old</StatusPill>}
                    </span>
                ) : (
                    <span className="text-muted-foreground text-sm">{row.kind === 'handheld' ? 'Not reported' : '—'}</span>
                ),
        },
        {
            id: 'last_seen_at',
            header: 'Last seen',
            enableSorting: true,
            cell: ({ row: { original: row } }) => (
                <div className="leading-tight whitespace-nowrap">
                    <div className="tabular-nums">{ago(row.lastSeenAt)}</div>
                    {row.lastSeenAt && <div className="text-muted-foreground text-xs tabular-nums">{shopDateTime(row.lastSeenAt)}</div>}
                </div>
            ),
        },
        {
            id: 'status',
            header: 'Status',
            meta: { mobile: 'aside' },
            cell: ({ row: { original: row } }) => <DeviceStatusBadge row={row} />,
        },
        {
            id: 'install',
            header: 'Install ID',
            cell: ({ row: { original: row } }) => {
                if (row.kind !== 'till') {
                    return <span className="text-muted-foreground text-sm">—</span>;
                }
                const skewed = row.clockSkewSeconds !== null && Math.abs(row.clockSkewSeconds) > clockSkewSeconds;

                return (
                    <div className="max-w-48 leading-tight">
                        <div className="truncate font-mono text-[13px]" title={row.installId ?? undefined}>
                            {row.installId ?? <span className="text-muted-foreground font-sans text-sm">No PC bound</span>}
                        </div>
                        <div className="text-muted-foreground flex items-center gap-1 text-xs whitespace-nowrap">
                            {row.keyLast4 && (
                                <>
                                    <KeyRound className="size-3" aria-hidden />
                                    <span>Key …{row.keyLast4}</span>
                                    <span aria-hidden>·</span>
                                </>
                            )}
                            <span className={cn('tabular-nums', skewed && 'text-destructive font-medium')} title="Till clock compared with ours">
                                <span className="sr-only">Clock: </span>
                                {clockSkewText(row.clockSkewSeconds)}
                            </span>
                        </div>
                    </div>
                );
            },
        },
        {
            id: 'actions',
            header: () => <span className="sr-only">Actions</span>,
            meta: { mobile: 'actions' },
            cell: ({ row: { original: row } }) => (
                <RowActions
                    label={`Actions for ${deviceTitle(row)} at ${row.company.name}`}
                    actions={[
                        {
                            label: 'Open licence',
                            icon: KeyRound,
                            href: row.licenceId ? route('admin.licences.show', row.licenceId) : undefined,
                            hidden: !row.licenceId,
                        },
                        { label: 'Open business', icon: Building2, href: route('admin.tenants.show', row.company.id) },
                        {
                            label: 'Release from PC',
                            icon: MonitorX,
                            destructive: true,
                            hidden: !(canRelease && row.canRelease),
                            onSelect: () => onRelease(row),
                        },
                    ]}
                />
            ),
        },
    ];
}
