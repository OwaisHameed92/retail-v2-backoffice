import { formatDate } from '@/components/admin/tenants/format';
import { type Option, type TenantListRow } from '@/components/admin/tenants/types';
import { DataTable, useTableQuery, type Paginated } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { EntityCell } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AdminLayout from '@/layouts/admin-layout';
import { type CompanyStatus } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Building2, Plus } from 'lucide-react';

interface TenantIndexProps {
    tenants: Paginated<TenantListRow>;
    filters: { status: CompanyStatus | null };
    statuses: Option<CompanyStatus>[];
    counts: Partial<Record<CompanyStatus, number>>;
    canManage: boolean;
}

const number = new Intl.NumberFormat('en-GB');

const columns: ColumnDef<TenantListRow>[] = [
    {
        id: 'name',
        accessorKey: 'name',
        header: 'Business',
        enableSorting: true,
        cell: ({ row }) => (
            <EntityCell
                name={row.original.name}
                shape="square"
                subline={row.original.legalName && row.original.legalName !== row.original.name ? row.original.legalName : undefined}
            />
        ),
    },
    {
        id: 'status',
        accessorKey: 'status',
        header: 'Status',
        enableSorting: true,
        cell: ({ row }) => <StatusBadge status={row.original.status} />,
    },
    {
        id: 'active_branches_count',
        accessorKey: 'branchesCount',
        header: 'Branches',
        enableSorting: true,
        meta: { align: 'right' },
        cell: ({ row }) => <span className="tabular-nums">{number.format(row.original.branchesCount)}</span>,
    },
    {
        id: 'active_registers_count',
        accessorKey: 'registersCount',
        header: 'Tills',
        enableSorting: true,
        meta: { align: 'right' },
        cell: ({ row }) => <span className="tabular-nums">{number.format(row.original.registersCount)}</span>,
    },
    {
        id: 'owner',
        accessorKey: 'ownerEmail',
        header: 'Owner',
        cell: ({ row }) =>
            row.original.ownerEmail ? (
                <span className="text-muted-foreground inline-block max-w-56 truncate align-middle">{row.original.ownerEmail}</span>
            ) : (
                <span className="text-muted-foreground/70">No owner</span>
            ),
    },
    {
        id: 'created_at',
        accessorKey: 'createdAt',
        header: 'Created',
        enableSorting: true,
        cell: ({ row }) => <span className="text-muted-foreground whitespace-nowrap tabular-nums">{formatDate(row.original.createdAt)}</span>,
    },
];

function StatusFilter({
    value,
    statuses,
    counts,
}: {
    value: CompanyStatus | null;
    statuses: Option<CompanyStatus>[];
    counts: TenantIndexProps['counts'];
}) {
    const { update } = useTableQuery({ only: ['tenants', 'filters'] });

    return (
        <Select value={value ?? 'all'} onValueChange={(next) => update({ status: next === 'all' ? undefined : next, page: 1 })}>
            <SelectTrigger className="h-9 w-full sm:w-48" aria-label="Filter by status">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="all">All statuses</SelectItem>
                {statuses.map((status) => (
                    <SelectItem key={status.value} value={status.value}>
                        {status.label}
                        <span className="text-muted-foreground ml-1 tabular-nums">({number.format(counts[status.value] ?? 0)})</span>
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

export default function TenantIndex({ tenants, filters, statuses, counts, canManage }: TenantIndexProps) {
    const total = Object.values(counts).reduce((sum, count) => sum + (count ?? 0), 0);
    const filtered = Boolean(tenants.meta.search || filters.status);

    return (
        <AdminLayout>
            <Head title="Tenants" />

            <PageHeader
                title="Tenants"
                description={`${number.format(total)} ${total === 1 ? 'customer business' : 'customer businesses'} with their shops and tills.`}
                actions={
                    canManage && (
                        <Button asChild>
                            <Link href={route('admin.tenants.create')}>
                                <Plus />
                                Add tenant
                            </Link>
                        </Button>
                    )
                }
            />

            <DataTable
                columns={columns}
                data={tenants.data}
                meta={tenants.meta}
                only={['tenants', 'filters']}
                searchPlaceholder="Search name, email or owner"
                filters={<StatusFilter value={filters.status} statuses={statuses} counts={counts} />}
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(route('admin.tenants.show', row.id))}
                empty={
                    filtered ? undefined : (
                        <EmptyState
                            icon={Building2}
                            title="No tenants yet"
                            body="Add your first customer: their business, first shop, tills and owner login."
                            action={
                                canManage && (
                                    <Button asChild>
                                        <Link href={route('admin.tenants.create')}>
                                            <Plus />
                                            Add tenant
                                        </Link>
                                    </Button>
                                )
                            }
                        />
                    )
                }
            />
        </AdminLayout>
    );
}
