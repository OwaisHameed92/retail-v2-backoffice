import { LeadBoard } from '@/components/admin/leads/lead-board';
import { leadColumns } from '@/components/admin/leads/lead-columns';
import { LEAD_INDEX_ONLY, LeadFilters } from '@/components/admin/leads/lead-filters';
import { type LeadIndexProps } from '@/components/admin/leads/types';
import { DataTable, DataTableToolbar, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Button } from '@/components/ui/button';
import { useBreakpoint } from '@/hooks/use-min-width';
import AdminLayout from '@/layouts/admin-layout';
import { formatNumber } from '@/lib/country';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import { AlarmClock, Columns3, Inbox, List, Percent, PhoneIncoming, Plus, Sparkles } from 'lucide-react';
import { useMemo } from 'react';

function ViewToggle({ view, onChange }: { view: LeadIndexProps['view']; onChange: (view: LeadIndexProps['view']) => void }) {
    const options = [
        { value: 'list', label: 'List', icon: List },
        { value: 'board', label: 'Board', icon: Columns3 },
    ] as const;

    return (
        <div role="group" aria-label="Show leads as" className="bg-muted inline-flex h-9 items-center rounded-lg p-0.5">
            {options.map((option) => {
                const Icon = option.icon;
                const active = view === option.value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        aria-pressed={active}
                        onClick={() => !active && onChange(option.value)}
                        className={cn(
                            'focus-visible:ring-ring/40 inline-flex h-8 items-center gap-1.5 rounded-md px-3 text-sm font-medium transition-colors outline-none focus-visible:ring-2',
                            active ? 'bg-card text-foreground shadow-card' : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        <Icon className="size-4" aria-hidden />
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}

export default function LeadIndex(props: LeadIndexProps) {
    const { view, leads, board, filters, counts, stats, can } = props;
    const breakpoint = useBreakpoint();
    const columns = useMemo(() => leadColumns({ breakpoint }), [breakpoint]);
    const { update, loading } = useTableQuery({ only: LEAD_INDEX_ONLY });
    const total = Object.entries(counts)
        .filter(([status]) => !['open', 'archived'].includes(status))
        .reduce((sum, [, count]) => sum + (count ?? 0), 0);
    const filtered = Boolean(filters.status || filters.source || filters.assigned || filters.followUp || props.search);

    const addLead = can.create && (
        <Button asChild>
            <Link href={route('admin.leads.create')}>
                <Plus />
                Add lead
            </Link>
        </Button>
    );

    const switchView = (next: LeadIndexProps['view']) =>
        update({
            view: next === 'board' ? 'board' : undefined,
            status: next === 'board' ? undefined : (filters.status ?? undefined),
            page: 1,
            sort: undefined,
            direction: undefined,
        });

    const filterBar = <LeadFilters {...props} />;

    return (
        <AdminLayout>
            <Head title="Leads" />

            <PageHeader
                title="Leads"
                description={
                    total === 0
                        ? 'Trial requests from the website, calls and walk-ins. Approve a 7-day trial to set a customer up.'
                        : `${formatNumber(total)} ${total === 1 ? 'trial request' : 'trial requests'}, ${formatNumber(counts.open ?? 0)} still open.`
                }
                actions={
                    <>
                        <ViewToggle view={view} onChange={switchView} />
                        {addLead}
                    </>
                }
            />

            <StatGrid>
                <StatCard label="New this week" value={formatNumber(stats.newThisWeek)} hint="Since Monday" icon={Sparkles} />
                <StatCard
                    label="Awaiting contact"
                    value={formatNumber(stats.awaitingContact)}
                    hint="Nobody has spoken to them yet"
                    icon={PhoneIncoming}
                    tone={stats.awaitingContact > 0 ? 'warning' : 'neutral'}
                    href={`${route('admin.leads.index')}?status=new`}
                />
                <StatCard
                    label="Follow-ups due"
                    value={formatNumber(stats.followUpsDue)}
                    hint={stats.overdueFollowUps > 0 ? `${formatNumber(stats.overdueFollowUps)} overdue` : 'By the end of today'}
                    icon={AlarmClock}
                    tone={stats.overdueFollowUps > 0 ? 'danger' : 'neutral'}
                    href={`${route('admin.leads.index')}?followUp=due`}
                />
                <StatCard
                    label="Conversion"
                    value={stats.conversionRate === null ? '—' : `${formatNumber(stats.conversionRate, { maximumFractionDigits: 1 })}%`}
                    hint={`${formatNumber(stats.convertedInWindow)} of ${formatNumber(stats.receivedInWindow)} in the last ${stats.windowDays} days`}
                    icon={Percent}
                    tone="success"
                />
            </StatGrid>

            {view === 'board' ? (
                <div className="flex flex-col gap-3">
                    <DataTableToolbar
                        search={props.search}
                        onSearch={(search) => update({ search })}
                        searchPlaceholder="Search business, contact, email or phone"
                        filters={filterBar}
                    />
                    <LeadBoard columns={board} loading={loading} listHref={(status) => `${route('admin.leads.index')}?status=${status}`} />
                </div>
            ) : (
                leads && (
                    <DataTable
                        columns={columns}
                        data={leads.data}
                        meta={leads.meta}
                        only={LEAD_INDEX_ONLY}
                        searchPlaceholder="Search business, contact, email or phone"
                        filters={filterBar}
                        getRowId={(row) => row.id}
                        onRowClick={(row) => router.visit(route('admin.leads.show', row.id))}
                        empty={
                            filtered ? undefined : (
                                <EmptyState
                                    icon={Inbox}
                                    title="No leads yet"
                                    body="Trial requests from the website arrive here. Add one yourself after a phone call or a walk-in."
                                    action={addLead}
                                />
                            )
                        }
                    />
                )
            )}
        </AdminLayout>
    );
}
