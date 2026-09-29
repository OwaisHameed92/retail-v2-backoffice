import { ago, clockSkewText, londonDateTime, ProblemPills, SyncStateBadge, TillStateBadge } from '@/components/till-health/format';
import { type TillHealthListRow } from '@/components/till-health/types';
import { EntityCell } from '@/components/shared/entity-cell';
import { StatusPill } from '@/components/shared/status-badge';
import { Badge } from '@/components/ui/badge';
import { relativeTime } from '@/lib/relative-time';
import { cn } from '@/lib/utils';
import { type ColumnDef } from '@tanstack/react-table';
import { Monitor, Star } from 'lucide-react';

/** Columns of the admin Till health list (module 2.7). Sortable ids are the server's sort keys. */
export function tillHealthColumns(): ColumnDef<TillHealthListRow>[] {
    return [
        {
            id: 'till',
            header: 'Till',
            meta: { mobile: 'title' },
            cell: ({ row: { original: row } }) => (
                <EntityCell
                    name={`${row.register.name} (${row.register.code})`}
                    subline={`${row.company.name} · ${row.branch.name}`}
                    icon={Monitor}
                    shape="square"
                    suffix={
                        row.register.isMainTill && (
                            <Badge variant="info" className="shrink-0">
                                <Star aria-hidden />
                                Main
                            </Badge>
                        )
                    }
                />
            ),
        },
        {
            id: 'status',
            header: 'State',
            meta: { mobile: 'aside' },
            cell: ({ row: { original: row } }) => <TillStateBadge state={row.state} label={row.stateLabel} />,
        },
        {
            id: 'last_seen_at',
            header: 'Last seen',
            enableSorting: true,
            cell: ({ row: { original: row } }) => (
                <div className="leading-tight whitespace-nowrap" title={londonDateTime(row.lastSeenAt)}>
                    <div className="tabular-nums">{ago(row.lastSeenAt)}</div>
                    <div className="text-muted-foreground text-xs">
                        {row.lastValidatedAt ? `Licence check ${relativeTime(row.lastValidatedAt)}` : 'No licence check yet'}
                    </div>
                </div>
            ),
        },
        {
            id: 'sync',
            header: 'Sync',
            cell: ({ row: { original: row } }) =>
                row.isSyncTill ? (
                    <div className="flex flex-col items-start gap-1 leading-tight">
                        <SyncStateBadge state={row.syncState} label={row.syncStateLabel} />
                        <span className="text-muted-foreground text-xs">
                            Push {row.lastPushAt ? relativeTime(row.lastPushAt) : 'never'} · pull {row.lastPullAt ? relativeTime(row.lastPullAt) : 'never'}
                        </span>
                    </div>
                ) : (
                    <span className="text-muted-foreground text-sm">Via main till</span>
                ),
        },
        {
            id: 'app_version',
            header: 'App',
            enableSorting: true,
            cell: ({ row: { original: row } }) =>
                row.appVersion ? (
                    <span className="inline-flex items-center gap-1.5 whitespace-nowrap">
                        <span className="font-mono text-[13px]">{row.appVersion}</span>
                        {row.appOutdated && <StatusPill tone="warning">Old</StatusPill>}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            id: 'clock_skew_seconds',
            header: 'Clock',
            enableSorting: true,
            // On phones the clock shows as a "Clock skew" problem instead.
            meta: { align: 'right', mobile: 'hidden' },
            cell: ({ row: { original: row } }) => (
                <span className={cn('whitespace-nowrap tabular-nums', row.clockSkewed ? 'text-destructive font-medium' : 'text-muted-foreground')}>
                    {clockSkewText(row.clockSkewSeconds)}
                </span>
            ),
        },
        {
            id: 'problem_count',
            header: 'Problems',
            enableSorting: true,
            cell: ({ row: { original: row } }) => <ProblemPills problems={row.problems} />,
        },
    ];
}
