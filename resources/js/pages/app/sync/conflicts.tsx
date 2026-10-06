import { clashColumns, conflictColumns } from '@/components/app/sync/conflict-columns';
import { ConflictFilters } from '@/components/app/sync/conflict-filters';
import { CONFLICT_INDEX_ONLY } from '@/components/app/sync/format';
import { type ConflictIndexProps } from '@/components/app/sync/types';
import { DataTable } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { PageTabs } from '@/components/shared/page-tabs';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/country';
import { relativeTime } from '@/lib/relative-time';
import { Head, router } from '@inertiajs/react';
import { Archive, CheckCircle2, Clock, GitCompareArrows, Store } from 'lucide-react';
import { useMemo } from 'react';

export default function SyncConflicts(props: ConflictIndexProps) {
    const { tab, counts, stats, filters } = props;
    const portalColumns = useMemo(() => conflictColumns(), []);
    const shopColumns = useMemo(() => clashColumns(), []);
    const index = route('app.sync.conflicts.index');
    const filtered =
        Boolean(props.search || filters.kind || filters.branch) || (tab === 'portal' ? filters.status !== 'open' : filters.resolution !== 'pending');

    return (
        <AppLayout>
            <Head title="Sync conflicts" />

            <PageHeader
                title="Sync conflicts"
                description="When a shop and the portal change the same thing before they sync, the portal's version wins. Review those changes here."
                tabs={
                    <PageTabs
                        label="Sync conflict lists"
                        tabs={[
                            { label: 'Portal review', href: index, active: tab === 'portal', count: counts.open },
                            { label: 'Shop clashes', href: `${index}?tab=shop`, active: tab === 'shop', count: counts.shopPending },
                        ]}
                    />
                }
            />

            {tab === 'portal' ? (
                <>
                    <StatGrid>
                        <StatCard
                            label="Needs review"
                            value={formatNumber(counts.open)}
                            hint={stats.oldestOpenAt ? `Oldest received ${relativeTime(stats.oldestOpenAt)}` : 'Nothing waiting'}
                            icon={GitCompareArrows}
                            tone={counts.open > 0 ? 'warning' : 'success'}
                        />
                        <StatCard
                            label="Shop changes kept out"
                            value={formatNumber(stats.hubRows)}
                            hint="The shop's version can still be used"
                            icon={Store}
                            tone="neutral"
                        />
                        <StatCard
                            label="Finished records"
                            value={formatNumber(stats.historic)}
                            hint="Historic records and deletes: review only"
                            icon={Archive}
                            tone="neutral"
                        />
                        <StatCard label="Resolved" value={formatNumber(counts.resolved)} hint="All time" icon={CheckCircle2} tone="success" />
                    </StatGrid>

                    {props.conflicts && (
                        <DataTable
                            columns={portalColumns}
                            data={props.conflicts.data}
                            meta={props.conflicts.meta}
                            only={CONFLICT_INDEX_ONLY}
                            searchPlaceholder="Search by what changed or its id"
                            filters={<ConflictFilters {...props} />}
                            getRowId={(row) => row.id}
                            onRowClick={(row) => router.visit(route('app.sync.conflicts.show', row.id))}
                            empty={
                                filtered ? undefined : (
                                    <EmptyState
                                        icon={CheckCircle2}
                                        tone="success"
                                        title="Nothing to review"
                                        body="Every change your shops sent was applied. If a shop and the portal ever change the same thing, it shows up here."
                                    />
                                )
                            }
                        />
                    )}
                </>
            ) : (
                <>
                    <p className="text-muted-foreground max-w-3xl text-sm">
                        A shop's till keeps the portal's change aside when someone at the shop edited the same thing and had not synced yet. The shop
                        decides on its Sync status screen; this list shows what is waiting there.
                    </p>
                    {props.clashes && (
                        <DataTable
                            columns={shopColumns}
                            data={props.clashes.data}
                            meta={props.clashes.meta}
                            only={CONFLICT_INDEX_ONLY}
                            searchPlaceholder="Search by what clashed"
                            filters={<ConflictFilters {...props} />}
                            getRowId={(row) => row.id}
                            onRowClick={(row) => router.visit(route('app.sync.clashes.show', row.id))}
                            empty={
                                filtered ? undefined : (
                                    <EmptyState
                                        icon={Clock}
                                        title="No clashes waiting at your shops"
                                        body="When a till keeps a portal change aside because of an unsynced edit at the shop, it shows up here after the till's next sync."
                                    />
                                )
                            }
                        />
                    )}
                </>
            )}
        </AppLayout>
    );
}
