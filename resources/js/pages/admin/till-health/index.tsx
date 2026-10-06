import { tillHealthColumns } from '@/components/admin/till-health/till-health-columns';
import { TillHealthRules } from '@/components/admin/till-health/till-health-rules';
import { DataTable, type Paginated } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { PageTabs } from '@/components/shared/page-tabs';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { ago } from '@/components/till-health/format';
import { type HealthThresholds, type TillHealthFilter, type TillHealthListRow, type TillHealthSummary } from '@/components/till-health/types';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { formatNumber } from '@/lib/country';
import { Head, Link, router } from '@inertiajs/react';
import { Activity, CircleAlert, Clock, Monitor, WifiOff, X } from 'lucide-react';
import { useMemo } from 'react';

interface TillHealthIndexProps {
    tills: Paginated<TillHealthListRow>;
    summary: TillHealthSummary;
    filters: { filter: TillHealthFilter | null; state: string | null; company: { id: string; name: string } | null };
    thresholds: HealthThresholds;
}

const tabs: { value: TillHealthFilter | null; label: string; count: (s: TillHealthSummary) => number }[] = [
    { value: null, label: 'All tills', count: (s) => s.tills },
    { value: 'attention', label: 'Needs attention', count: (s) => s.attention },
    { value: 'offline', label: 'Offline', count: (s) => s.offline },
    { value: 'oldVersion', label: 'Old version', count: (s) => s.oldVersion },
    { value: 'sync', label: 'Failing sync', count: (s) => s.sync },
    { value: 'clockSkew', label: 'Clock skew', count: (s) => s.clockSkew },
];

export default function TillHealthIndex({ tills, summary, filters, thresholds }: TillHealthIndexProps) {
    const columns = useMemo(() => tillHealthColumns(), []);
    const href = (filter: TillHealthFilter | null) =>
        route('admin.till-health.index', { ...(filter ? { filter } : {}), ...(filters.company ? { company: filters.company.id } : {}) });
    const activated = summary.tills - summary.notActivated;

    return (
        <AdminLayout>
            <Head title="Till health" />

            <PageHeader
                title="Till health"
                description={
                    summary.tills === 0
                        ? 'Online state, app versions, sync and clocks of every till.'
                        : `${formatNumber(summary.tills)} ${summary.tills === 1 ? 'till' : 'tills'} in ${formatNumber(summary.shops)} ${summary.shops === 1 ? 'shop' : 'shops'}. Checked ${ago(summary.checkedAt, 'not yet')}, every ${thresholds.refreshMinutes} minutes.`
                }
                tabs={
                    <PageTabs
                        label="Till health filters"
                        tabs={tabs.map((tab) => ({
                            label: tab.label,
                            href: href(tab.value),
                            active: filters.filter === tab.value,
                            count: tab.count(summary),
                        }))}
                    />
                }
            />

            {filters.company && (
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="text-muted-foreground">Showing one business:</span>
                    <Link href={route('admin.tenants.show', filters.company.id)} className="text-primary font-medium hover:underline">
                        {filters.company.name}
                    </Link>
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={route('admin.till-health.index', filters.filter ? { filter: filters.filter } : {})}>
                            <X />
                            Show all businesses
                        </Link>
                    </Button>
                </div>
            )}

            <StatGrid>
                <StatCard
                    label="Online"
                    value={<span className="tabular-nums">{formatNumber(summary.online)}</span>}
                    hint={activated > 0 ? `of ${formatNumber(activated)} activated tills` : 'No activated tills yet'}
                    icon={Monitor}
                    tone="success"
                />
                <StatCard
                    label="Stale"
                    value={<span className="tabular-nums">{formatNumber(summary.stale)}</span>}
                    hint="Heard from, but not recently"
                    icon={Clock}
                    tone="warning"
                />
                <StatCard
                    label="Offline"
                    value={<span className="tabular-nums">{formatNumber(summary.offline)}</span>}
                    hint={`No contact for ${thresholds.validateOfflineHours} h, or main till ${thresholds.syncOfflineHours} h`}
                    icon={WifiOff}
                    tone="danger"
                    href={href('offline')}
                />
                <StatCard
                    label="Needs attention"
                    value={<span className="tabular-nums">{formatNumber(summary.attention)}</span>}
                    hint="Offline, old version, sync or clock"
                    icon={CircleAlert}
                    tone={summary.attention > 0 ? 'danger' : 'neutral'}
                    href={href('attention')}
                />
            </StatGrid>

            <DataTable
                columns={columns}
                data={tills.data}
                meta={tills.meta}
                only={['tills', 'summary', 'filters']}
                searchPlaceholder="Search business, shop, till or PC"
                getRowId={(row) => row.id}
                onRowClick={(row) =>
                    router.visit(row.licenceId ? route('admin.licences.show', row.licenceId) : route('admin.tenants.show', row.company.id))
                }
                empty={
                    summary.tills === 0 ? (
                        <EmptyState
                            icon={Activity}
                            title="No tills to watch yet"
                            body={`Every active till of every business shows here with its state, app version, sync and clock. Health is worked out every ${thresholds.refreshMinutes} minutes.`}
                        />
                    ) : undefined
                }
            />

            <TillHealthRules thresholds={thresholds} />
        </AdminLayout>
    );
}
