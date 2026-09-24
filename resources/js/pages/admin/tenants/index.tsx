import { formatDate } from '@/components/admin/tenants/format';
import { type Option, type TenantListRow } from '@/components/admin/tenants/types';
import { DataTable, useTableQuery, type Paginated } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
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
            <div className="min-w-0">
                <div className="truncate font-medium">{row.original.name}</div>
                {row.original.legalName && row.original.legalName !== row.original.name && (
                    <div className="text-muted-foreground truncate text-xs">{row.original.legalName}</div>
                )}
                <div className="mt-1 sm:hidden">
                    <StatusBadge status={row.original.status} />
                </div>
            </div>
        ),
    },
    {
        id: 'status',
        accessorKey: 'status',
        header: () => <span className="hidden sm:inline">Status</span>,
        enableSorting: true,
        cell: ({ row }) => (
            <span className="hidden sm:inline">
                <StatusBadge status={row.original.status} />
            </span>
        ),
    },
    {
        id: 'active_branches_count',
        accessorKey: 'branchesCount',
        header: 'Branches',
        enableSorting: true,
        cell: ({ row }) => <span className="tabular-nums">{number.format(row.original.branchesCount)}</span>,
    },
    {
        id: 'active_registers_count',
        accessorKey: 'registersCount',
        header: 'Tills',
        enableSorting: true,
        cell: ({ row }) => <span className="tabular-nums">{number.format(row.original.registersCount)}</span>,
    },
    {
        id: 'owner',
        accessorKey: 'ownerEmail',
        header: () => <span className="hidden md:inline">Owner</span>,
        cell: ({ row }) => (
            <span className="text-muted-foreground hidden max-w-56 truncate md:inline-block">{row.original.ownerEmail ?? 'No owner'}</span>
        ),
    },
    {
        id: 'created_at',
        accessorKey: 'createdAt',
        header: () => <span className="hidden lg:inline">Created</span>,
        enableSorting: true,
        cell: ({ row }) => <span className="text-muted-foreground hidden whitespace-nowrap lg:inline">{formatDate(row.original.createdAt)}</span>,
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
            <SelectTrigger className="h-9 w-full sm:w-44" aria-label="Filter by status">
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
