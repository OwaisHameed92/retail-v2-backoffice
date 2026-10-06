import { RequestStatus, RequestType, when } from '@/components/app/privacy/request-parts';
import { FilterSelect } from '@/components/app/setup/fields';
import { DataTable, useTableQuery, type Paginated } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { EntityCell } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import AdminLayout from '@/layouts/admin-layout';
import { ukOnly } from '@/lib/country-text';
import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { CalendarClock, Hourglass, LockKeyhole } from 'lucide-react';

interface AdminDataRequestRow {
    id: string;
    companyId: string;
    company: string;
    type: 'export' | 'erasure';
    typeLabel: string;
    status: 'completed' | 'tillPending';
    statusLabel: string;
    source: 'owner' | 'retention';
    tillSteps: number;
    createdAt: string | null;
    completedAt: string | null;
}

interface Props {
    requests: Paginated<AdminDataRequestRow>;
    filters: { type: string | null; status: string | null };
    counts: { total: number; tillPending: number; last30Days: number };
}

const ONLY = ['requests', 'filters', 'counts'];

const columns: ColumnDef<AdminDataRequestRow>[] = [
    { id: 'company', header: 'Business', cell: ({ row }) => <EntityCell name={row.original.company} shape="square" />, meta: { mobile: 'title' } },
    { id: 'type', header: 'Request', cell: ({ row }) => <RequestType row={row.original} /> },
    { id: 'status', header: 'Status', cell: ({ row }) => <RequestStatus row={row.original} />, meta: { mobile: 'aside' } },
    { id: 'source', header: 'Raised by', cell: ({ row }) => (row.original.source === 'retention' ? 'Data retention' : 'Business owner') },
    {
        id: 'created_at',
        header: 'Requested',
        enableSorting: true,
        cell: ({ row }) => <span className="tabular-nums">{when(row.original.createdAt)}</span>,
    },
    {
        id: 'steps',
        header: 'Till steps open',
        cell: ({ row }) => (row.original.status === 'tillPending' ? row.original.tillSteps : '—'),
        meta: { align: 'right' },
    },
];

/** Customer data requests across every business (module 7.7), for support. No customer details are shown. */
export default function AdminDataRequests({ requests, filters, counts }: Props) {
    const { update } = useTableQuery({ only: ONLY });

    return (
        <AdminLayout>
            <Head title="Data requests" />
            <PageHeader
                title="Data requests"
                description={`Customer data exports and erasures made by businesses${ukOnly(' (UK GDPR)', '')}. Customer details stay with the business and are not shown here.`}
            />

            <StatGrid columns={3}>
                <StatCard label="All requests" value={counts.total} icon={LockKeyhole} tone="neutral" />
                <StatCard
                    label="Waiting for tills"
                    value={counts.tillPending}
                    hint="Till-owned records still to clear"
                    icon={Hourglass}
                    tone={counts.tillPending > 0 ? 'warning' : 'neutral'}
                />
                <StatCard label="Last 30 days" value={counts.last30Days} icon={CalendarClock} tone="primary" />
            </StatGrid>

            <DataTable
                columns={columns}
                data={requests.data}
                meta={requests.meta}
                only={ONLY}
                searchPlaceholder="Search business"
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(route('admin.tenants.show', row.companyId))}
                filters={
                    <>
                        <FilterSelect
                            value={filters.type}
                            onChange={(type) => update({ type, page: undefined })}
                            all="Every request"
                            options={[
                                { value: 'export', label: 'Data export' },
                                { value: 'erasure', label: 'Erasure' },
                            ]}
                            label="Filter by request"
                        />
                        <FilterSelect
                            value={filters.status}
                            onChange={(status) => update({ status, page: undefined })}
                            all="Any status"
                            options={[
                                { value: 'completed', label: 'Completed' },
                                { value: 'tillPending', label: 'Waiting for the tills' },
                            ]}
                            label="Filter by status"
                        />
                    </>
                }
                empty={
                    requests.meta.search || filters.type || filters.status ? undefined : (
                        <EmptyState icon={LockKeyhole} title="No data requests yet" body="Exports and erasures made by any business appear here." />
                    )
                }
            />
        </AdminLayout>
    );
}
