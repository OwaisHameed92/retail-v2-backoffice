import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { formatNumber } from '@/lib/country';
import { Link } from '@inertiajs/react';
import { ArrowDownToLine, ArrowUpFromLine, CloudOff } from 'lucide-react';
import { When } from './format';
import { ListTable, type ListColumn } from './list-table';
import { type SyncJobsPageProps, type TrafficRow } from './types';

function columns(quietHours: number): ListColumn<TrafficRow>[] {
    return [
        {
            key: 'business',
            header: 'Business',
            className: 'max-w-56',
            cell: (row) => (
                <div className="min-w-0">
                    <Link href={route('admin.tenants.show', row.business.id)} className="text-primary font-medium break-words hover:underline">
                        {row.business.name}
                    </Link>
                    <div className="text-muted-foreground mt-0.5 text-xs">
                        {formatNumber(row.shops)} {row.shops === 1 ? 'shop' : 'shops'}
                    </div>
                </div>
            ),
        },
        {
            key: 'state',
            header: 'State',
            mobile: 'aside',
            cell: (row) =>
                row.quiet ? <StatusPill tone="warning">No push for {quietHours} h</StatusPill> : <StatusPill tone="success">Pushing</StatusPill>,
        },
        {
            key: 'push',
            header: 'Last push',
            cell: (row) => (
                <span className="inline-flex items-center gap-1.5">
                    <ArrowUpFromLine className="text-muted-foreground size-3.5" aria-hidden />
                    <When at={row.lastPushAt} />
                </span>
            ),
        },
        {
            key: 'pull',
            header: 'Last pull',
            cell: (row) => (
                <span className="inline-flex items-center gap-1.5">
                    <ArrowDownToLine className="text-muted-foreground size-3.5" aria-hidden />
                    <When at={row.lastPullAt} />
                </span>
            ),
        },
        {
            key: 'today',
            header: 'Rows today',
            align: 'right',
            cell: (row) => (
                <span>
                    {formatNumber(row.acceptedToday)}
                    {row.rejectedToday > 0 && <span className="text-destructive ml-1.5 font-medium">{formatNumber(row.rejectedToday)} refused</span>}
                </span>
            ),
        },
    ];
}

/** The 10 businesses whose tills pushed most recently, and those quiet for more than a day (amber). */
export function TillTrafficCard({ traffic }: { traffic: SyncJobsPageProps['traffic'] }) {
    const cols = columns(traffic.quietHours);

    return (
        <div className="grid gap-6">
            <SectionCard
                flush
                title="Recent till traffic"
                description={`The ${traffic.recent.length < 10 ? formatNumber(traffic.recent.length) : '10'} businesses whose tills pushed most recently, of ${formatNumber(traffic.businesses)} that sync.`}
            >
                {traffic.recent.length === 0 ? (
                    <EmptyState
                        icon={ArrowUpFromLine}
                        size="sm"
                        title="No till has pushed yet"
                        body="Businesses appear here once a till sends its first changes."
                    />
                ) : (
                    <ListTable label="Recent till traffic" columns={cols} rows={traffic.recent} getKey={(row) => row.business.id} />
                )}
            </SectionCard>
            <SectionCard
                flush
                title="Quiet tills"
                description={`Trading businesses whose tills pushed in the last 30 days but not in the last ${traffic.quietHours} hours.`}
                actions={traffic.quietTotal > 0 ? <StatusPill tone="warning">{formatNumber(traffic.quietTotal)} quiet</StatusPill> : undefined}
                footer={
                    traffic.quietTotal > traffic.quiet.length ? (
                        <span className="text-muted-foreground">
                            Showing {formatNumber(traffic.quiet.length)} of {formatNumber(traffic.quietTotal)}. Till health lists every till.
                        </span>
                    ) : undefined
                }
            >
                {traffic.quiet.length === 0 ? (
                    <EmptyState
                        icon={CloudOff}
                        tone="success"
                        size="sm"
                        title="No quiet tills"
                        body={`Every trading business pushed in the last ${traffic.quietHours} hours.`}
                    />
                ) : (
                    <ListTable label="Quiet tills" columns={cols} rows={traffic.quiet} getKey={(row) => row.business.id} />
                )}
            </SectionCard>
        </div>
    );
}
