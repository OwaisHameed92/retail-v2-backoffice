import { PartnerCancelledPill, PartnerRequestStatusBadge, partnerRequestLabels } from '@/components/admin/partners/partner-request-status';
import { type PartnerRequestRow, type PartnerRequestStatus } from '@/components/admin/partners/types';
import { formatDate } from '@/components/admin/tenants/format';
import { DataTable, useTableQuery, type Paginated } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { EntityCell } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AdminLayout from '@/layouts/admin-layout';
import { formatNumber } from '@/lib/country';
import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { BadgeCheck, CircleX, Handshake, Hourglass, TriangleAlert } from 'lucide-react';

const PARTNER_CANCELLED = 'partnerCancelled';
const ONLY = ['requests', 'filters', 'counts', 'partnerCancelledCount'];

interface PartnerRequestIndexProps {
    requests: Paginated<PartnerRequestRow>;
    filters: { status: PartnerRequestStatus | typeof PARTNER_CANCELLED | null; partner: string | null };
    statuses: { value: PartnerRequestStatus; label: string }[];
    counts: Partial<Record<PartnerRequestStatus, number>>;
    partnerCancelledCount: number;
    partners: { value: string; label: string }[];
}

const columns: ColumnDef<PartnerRequestRow>[] = [
    {
        id: 'business_name',
        accessorKey: 'businessName',
        header: 'Business',
        enableSorting: true,
        cell: ({ row }) => (
            <EntityCell
                name={row.original.businessName}
                shape="square"
                subline={`${row.original.partner ?? 'Partner'} · ${row.original.externalId}`}
            />
        ),
    },
    {
        id: 'status',
        accessorKey: 'status',
        header: 'Status',
        enableSorting: true,
        cell: ({ row }) => (
            <span className="inline-flex flex-wrap items-center gap-1.5">
                <PartnerRequestStatusBadge status={row.original.status} />
                {row.original.partnerCancelled && <PartnerCancelledPill />}
            </span>
        ),
    },
    {
        id: 'seller',
        accessorKey: 'sellerName',
        header: 'Seller',
        cell: ({ row }) => (
            <span className="grid min-w-0">
                <span className="truncate">{row.original.sellerName}</span>
                <span className="text-muted-foreground truncate text-xs">{row.original.sellerEmail}</span>
            </span>
        ),
    },
    {
        id: 'tills',
        accessorKey: 'tills',
        header: 'Tills',
        meta: { align: 'right' },
        cell: ({ row }) => (
            <span className="tabular-nums">
                {formatNumber(row.original.tills)}
                {row.original.shops > 1 && <span className="text-muted-foreground"> in {formatNumber(row.original.shops)} shops</span>}
            </span>
        ),
    },
    {
        id: 'created_at',
        accessorKey: 'createdAt',
        header: 'Received',
        enableSorting: true,
        cell: ({ row }) => <span className="text-muted-foreground whitespace-nowrap tabular-nums">{formatDate(row.original.createdAt)}</span>,
    },
];

function Filters({ filters, statuses, counts, partnerCancelledCount, partners }: Omit<PartnerRequestIndexProps, 'requests'>) {
    const { update } = useTableQuery({ only: ONLY });

    return (
        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-1 sm:flex-row sm:flex-wrap sm:items-center">
            <Select value={filters.status ?? 'all'} onValueChange={(next) => update({ status: next === 'all' ? undefined : next, page: 1 })}>
                <SelectTrigger className="h-9 w-full sm:w-56" aria-label="Filter by status">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All statuses</SelectItem>
                    {statuses.map((status) => (
                        <SelectItem key={status.value} value={status.value}>
                            {partnerRequestLabels[status.value]}
                            <span className="text-muted-foreground ml-1 tabular-nums">({formatNumber(counts[status.value] ?? 0)})</span>
                        </SelectItem>
                    ))}
                    <SelectItem value={PARTNER_CANCELLED}>
                        Partner cancelled the deal
                        <span className="text-muted-foreground ml-1 tabular-nums">({formatNumber(partnerCancelledCount)})</span>
                    </SelectItem>
                </SelectContent>
            </Select>
            {partners.length > 1 && (
                <Select value={filters.partner ?? 'all'} onValueChange={(next) => update({ partner: next === 'all' ? undefined : next, page: 1 })}>
                    <SelectTrigger className="h-9 w-full sm:w-48" aria-label="Filter by partner">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All partners</SelectItem>
                        {partners.map((partner) => (
                            <SelectItem key={partner.value} value={partner.value}>
                                {partner.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            )}
        </div>
    );
}

/**
 * Admin → Partner requests: deals partners (Zivaro) sent through the partner API. Each waits here until someone
 * reviews it, then approves it into a business or rejects it with a reason the partner sees.
 */
export default function PartnerRequestIndex(props: PartnerRequestIndexProps) {
    const { requests, filters, counts, partnerCancelledCount } = props;
    const total = Object.values(counts).reduce((sum, count) => sum + (count ?? 0), 0);
    const filtered = Boolean(requests.meta.search || filters.status || filters.partner);

    return (
        <AdminLayout>
            <Head title="Partner requests" />

            <PageHeader
                title="Partner requests"
                description="New businesses sold by partners. Review each one, then approve it to set the business up, or reject it with a reason."
            />

            <StatGrid>
                <StatCard label="Waiting for review" value={formatNumber(counts.pendingReview ?? 0)} icon={Hourglass} tone="warning" />
                <StatCard label="Approved" value={formatNumber(counts.approved ?? 0)} icon={BadgeCheck} tone="success" />
                <StatCard label="Rejected" value={formatNumber(counts.rejected ?? 0)} icon={CircleX} tone="neutral" />
                <StatCard
                    label="Partner cancelled"
                    value={formatNumber(partnerCancelledCount)}
                    icon={TriangleAlert}
                    tone={partnerCancelledCount > 0 ? 'danger' : 'neutral'}
                    hint={partnerCancelledCount > 0 ? 'Check these businesses' : 'None'}
                />
            </StatGrid>

            <DataTable
                columns={columns}
                data={requests.data}
                meta={requests.meta}
                only={ONLY}
                searchPlaceholder="Search business, deal or seller"
                filters={<Filters {...props} />}
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(route('admin.partner-requests.show', row.id))}
                empty={
                    filtered ? undefined : (
                        <EmptyState
                            icon={Handshake}
                            title={total === 0 ? 'No partner requests yet' : 'Nothing here'}
                            body="When a partner records a signed EPOS deal, it arrives here for review. Partners and their API keys are set up in Settings → Partners."
                        />
                    )
                }
            />
        </AdminLayout>
    );
}
