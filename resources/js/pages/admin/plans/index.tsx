import { PlanRowMenu } from '@/components/admin/plans/plan-actions';
import { formatDays, formatMoney } from '@/components/admin/plans/plan-format';
import { PlanStatusBadge } from '@/components/admin/plans/plan-status-badge';
import { type PlanRow } from '@/components/admin/plans/types';
import { DataTable, useTableQuery, type Paginated } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { EntityCell } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AdminLayout from '@/layouts/admin-layout';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Layers, Plus, SearchX } from 'lucide-react';

type Counts = Record<'current' | 'all' | 'active' | 'hidden' | 'inactive' | 'archived', number>;

interface PlansIndexProps {
    plans: Paginated<PlanRow>;
    filters: { status: string | null };
    counts: Counts;
}

const STATUS_FILTERS: { value: string; label: string; count: keyof Counts }[] = [
    { value: 'current', label: 'All current', count: 'current' },
    { value: 'active', label: 'Active', count: 'active' },
    { value: 'hidden', label: 'Hidden', count: 'hidden' },
    { value: 'inactive', label: 'Inactive', count: 'inactive' },
    { value: 'archived', label: 'Archived', count: 'archived' },
    { value: 'all', label: 'All, including archived', count: 'all' },
];

const columns: ColumnDef<PlanRow>[] = [
    {
        id: 'name',
        header: 'Plan',
        enableSorting: true,
        cell: ({ row }) => <EntityCell name={row.original.name} subline={row.original.code} monoSubline shape="square" icon={Layers} />,
    },
    {
        id: 'price_monthly',
        header: 'Monthly / unit',
        enableSorting: true,
        meta: { align: 'right' },
        cell: ({ row }) =>
            row.original.billingType === 'setupOnly' ? (
                <span className="text-muted-foreground">Setup fee only</span>
            ) : (
                <span className="font-medium tabular-nums">
                    {formatMoney(row.original.priceMonthly)}
                    <span className="text-muted-foreground font-normal"> / {row.original.pricingMode === 'perBranch' ? 'branch' : 'till'}</span>
                </span>
            ),
    },
    {
        id: 'price_yearly',
        header: 'Yearly / unit',
        enableSorting: true,
        meta: { align: 'right' },
        cell: ({ row }) => <span className="tabular-nums">{formatMoney(row.original.priceYearly)}</span>,
    },
    {
        id: 'trial_days',
        header: 'Trial',
        enableSorting: true,
        meta: { align: 'right' },
        cell: ({ row }) => <span className="whitespace-nowrap tabular-nums">{formatDays(row.original.trialDays, 'No trial')}</span>,
    },
    {
        id: 'features',
        header: 'Features',
        meta: { align: 'right' },
        cell: ({ row }) => <span className="tabular-nums">{row.original.featureCount}</span>,
    },
    {
        id: 'status',
        header: 'Status',
        cell: ({ row }) => <PlanStatusBadge status={row.original.status} label={row.original.statusLabel} />,
    },
    {
        id: 'actions',
        header: () => <span className="sr-only">Actions</span>,
        meta: { cellClassName: 'w-12' },
        cell: ({ row }) => <PlanRowMenu plan={row.original} />,
    },
];

function StatusFilter({ value, counts }: { value: string; counts: Counts }) {
    const { update } = useTableQuery({ only: ['plans', 'filters', 'counts'] });

    return (
        <Select value={value} onValueChange={(next) => update({ status: next === 'current' ? undefined : next, page: 1 })}>
            <SelectTrigger className="h-9 w-full sm:w-52" aria-label="Filter by status">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {STATUS_FILTERS.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        <span className="flex w-full items-center justify-between gap-3">
                            {option.label}
                            <span className="text-muted-foreground tabular-nums">{counts[option.count]}</span>
                        </span>
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

export default function PlansIndex({ plans, filters, counts }: PlansIndexProps) {
    const status = filters.status ?? 'current';
    const filtered = status !== 'current' || !!plans.meta.search;
    const total = plans.meta.total;

    const addButton = (
        <Button asChild>
            <Link href={route('admin.plans.create')}>
                <Plus />
                Add plan
            </Link>
        </Button>
    );

    return (
        <AdminLayout>
            <Head title="Plans" />
            <PageHeader
                title="Plans"
                description={
                    <span className="tabular-nums">
                        Subscription plans licences are sold on. Prices are per till or per branch. {total} {total === 1 ? 'plan' : 'plans'}
                        {filtered ? ' match' : ''}.
                    </span>
                }
                actions={addButton}
            />

            <DataTable
                columns={columns}
                data={plans.data}
                meta={plans.meta}
                only={['plans', 'filters', 'counts']}
                searchPlaceholder="Search name, code or description"
                filters={<StatusFilter value={status} counts={counts} />}
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(route('admin.plans.show', row.id))}
                empty={
                    counts.all === 0 ? (
                        <EmptyState
                            icon={Layers}
                            title="No plans yet"
                            body="Create the first plan so licences can be issued on it. You set the price per till or per branch, the free trial and the features."
                            action={addButton}
                        />
                    ) : (
                        <EmptyState icon={SearchX} title="No plans match" body="Try a different search or status filter." tone="neutral" />
                    )
                }
            />
        </AdminLayout>
    );
}
