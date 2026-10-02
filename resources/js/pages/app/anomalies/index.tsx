import { AnomalyStatusBadge, formatDateTime, formatDay, SeverityBadge, STATUS_FILTERS } from '@/components/app/anomalies/format';
import { StatusActions } from '@/components/app/anomalies/status-actions';
import { type AnomalyIndexProps, type AnomalyRow } from '@/components/app/anomalies/types';
import { FilterSelect } from '@/components/app/setup/fields';
import { DataTable, type TableParams, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { BellRing, CheckCheck, Lock, ShieldAlert, ShieldCheck, Siren, UserRound } from 'lucide-react';

function columns(canManage: boolean): ColumnDef<AnomalyRow>[] {
    const cols: ColumnDef<AnomalyRow>[] = [
        {
            id: 'finding',
            header: 'Finding',
            meta: { mobile: 'title' },
            cell: ({ row }) => (
                <div className="max-w-xl min-w-0">
                    <Link href={route('app.anomalies.show', row.original.id)} className="hover:text-primary line-clamp-2 font-medium hover:underline">
                        {row.original.title}
                    </Link>
                    <p className="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-x-2 text-xs">
                        <span>{row.original.kindLabel}</span>
                        {row.original.staffLevel && (
                            <span className="inline-flex items-center gap-1">
                                <UserRound className="size-3" aria-hidden />
                                Staff member
                            </span>
                        )}
                        {row.original.occurrences > 1 && <span>Seen {row.original.occurrences} times</span>}
                    </p>
                </div>
            ),
        },
        { id: 'severity', header: 'Severity', meta: { mobile: 'aside' }, cell: ({ row }) => <SeverityBadge severity={row.original.severity} /> },
        { id: 'shop', header: 'Shop', cell: ({ row }) => row.original.shop },
        {
            id: 'trading_day',
            header: 'Day',
            enableSorting: true,
            cell: ({ row }) => <span className="tabular-nums">{formatDay(row.original.tradingDay)}</span>,
        },
        { id: 'status', header: 'Status', cell: ({ row }) => <AnomalyStatusBadge status={row.original.status} /> },
        {
            id: 'detected_at',
            header: 'Found',
            enableSorting: true,
            meta: { mobile: 'hidden' },
            cell: ({ row }) => <span className="text-muted-foreground tabular-nums">{formatDateTime(row.original.detectedAt)}</span>,
        },
    ];

    if (canManage) {
        cols.push({
            id: 'actions',
            header: () => <span className="sr-only">Actions</span>,
            cell: ({ row }) => (
                // Buttons and the dismiss dialog (a React child, even in its portal) must not open the row.
                <div onClick={(e) => e.stopPropagation()}>
                    <StatusActions anomaly={row.original} />
                </div>
            ),
        });
    }

    return cols;
}

/** Unusual activity (module 6.6): findings of the anomaly checks, with filters, counts and status actions. */
export default function AnomaliesIndex({ anomalies, summary, filters, options, seesStaff, canManage }: AnomalyIndexProps) {
    const { update, loading } = useTableQuery();
    const set = (params: TableParams) => update({ ...params, page: undefined });
    const filtered = filters.status !== 'open' || filters.severity !== null || filters.kind !== null;

    return (
        <AppLayout>
            <Head title="Unusual activity" />
            <PageHeader
                title="Unusual activity"
                description="Figures far from each shop's normal, found by hourly and overnight checks against the last 8 weeks. A finding is worth a look, not proof of wrongdoing."
            />
            <div className="grid gap-6">
                {!seesStaff && (
                    <p className="text-muted-foreground text-sm">Findings about individual staff members are shown to owners and managers only.</p>
                )}
                <StatGrid>
                    <StatCard label="New" value={summary.new.toLocaleString('en-GB')} hint="Not looked at yet" icon={BellRing} tone="primary" />
                    <StatCard
                        label="Serious and open"
                        value={summary.highOpen.toLocaleString('en-GB')}
                        hint="High severity"
                        icon={Siren}
                        tone="danger"
                    />
                    <StatCard
                        label="Acknowledged"
                        value={summary.acknowledged.toLocaleString('en-GB')}
                        hint="Being looked into"
                        icon={CheckCheck}
                        tone="neutral"
                    />
                    <StatCard
                        label="Dismissed"
                        value={summary.dismissed.toLocaleString('en-GB')}
                        hint="With a reason"
                        icon={ShieldCheck}
                        tone="neutral"
                    />
                </StatGrid>

                <SectionCard title="Findings" description={`${anomalies.meta.total.toLocaleString('en-GB')} in these dates, newest first.`} flush>
                    <DataTable
                        columns={columns(canManage)}
                        data={anomalies.data}
                        meta={anomalies.meta}
                        onChange={update}
                        loading={loading}
                        searchable={false}
                        getRowId={(row) => row.id}
                        onRowClick={(row) => router.visit(route('app.anomalies.show', row.id))}
                        filters={
                            <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-1 sm:flex-row sm:flex-wrap sm:items-center">
                                <div className="flex items-center gap-1.5">
                                    <Input
                                        type="date"
                                        className="h-9 w-full sm:w-38"
                                        aria-label="From"
                                        value={filters.from}
                                        max={filters.to}
                                        onChange={(e) => e.target.value && set({ from: e.target.value })}
                                    />
                                    <span className="text-muted-foreground text-sm">to</span>
                                    <Input
                                        type="date"
                                        className="h-9 w-full sm:w-38"
                                        aria-label="To"
                                        value={filters.to}
                                        min={filters.from}
                                        onChange={(e) => e.target.value && set({ to: e.target.value })}
                                    />
                                </div>
                                <FilterSelect
                                    value={filters.status === 'open' ? null : filters.status}
                                    onChange={(status) => set({ status })}
                                    all={STATUS_FILTERS[0].label}
                                    options={STATUS_FILTERS.slice(1)}
                                    label="Filter by status"
                                    width="sm:w-52"
                                />
                                <FilterSelect
                                    value={filters.severity}
                                    onChange={(severity) => set({ severity })}
                                    all="Any severity"
                                    options={options.severities}
                                    label="Filter by severity"
                                    width="sm:w-36"
                                />
                                <FilterSelect
                                    value={filters.kind}
                                    onChange={(kind) => set({ kind })}
                                    all="Every kind"
                                    options={options.kinds}
                                    label="Filter by kind"
                                    width="sm:w-64"
                                />
                                {filters.shopLocked ? (
                                    <StatusPill tone="neutral" className="h-9 gap-1.5 px-3">
                                        <Lock className="size-3.5" />
                                        {options.shops[0]?.label ?? 'Your shop'}
                                    </StatusPill>
                                ) : (
                                    options.shops.length > 1 && (
                                        <FilterSelect
                                            value={filters.shop}
                                            onChange={(shop) => set({ shop: shop ?? 'all' })}
                                            all="Every shop"
                                            options={options.shops}
                                            label="Filter by shop"
                                        />
                                    )
                                )}
                            </div>
                        }
                        empty={
                            <EmptyState
                                icon={ShieldAlert}
                                title={filtered ? 'Nothing matches these filters' : 'Nothing unusual in these dates'}
                                body={
                                    filtered
                                        ? 'Try every status, any severity or a longer date range.'
                                        : 'The checks run every hour and overnight. Anything far from normal shows up here and in your alerts.'
                                }
                            />
                        }
                    />
                </SectionCard>
            </div>
        </AppLayout>
    );
}
