import { FilterSelect, ReadOnlyNotice, STATUS_OPTIONS } from '@/components/app/setup/fields';
import { type SupplierIndexProps, type SupplierRow } from '@/components/app/setup/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { EntityCell } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
import { RowActions } from '@/components/shared/row-actions';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { CheckCircle2, CircleOff, Pencil, Plus, Trash2, Truck } from 'lucide-react';
import { useMemo, useState } from 'react';

const ONLY = ['suppliers', 'filters', 'counts'];
const number = new Intl.NumberFormat('en-GB');
const dash = <span className="text-muted-foreground">—</span>;

export default function Suppliers({ suppliers, filters, counts, canEdit }: SupplierIndexProps) {
    const { update } = useTableQuery({ only: ONLY });
    const [removing, setRemoving] = useState<SupplierRow | null>(null);
    const filtered = Boolean(suppliers.meta.search) || filters.status !== 'all';

    const columns = useMemo<ColumnDef<SupplierRow>[]>(
        () => [
            {
                id: 'name',
                header: 'Supplier',
                enableSorting: true,
                cell: ({ row }) => (
                    <EntityCell name={row.original.name} subline={row.original.code} monoSubline shape="square" icon={Truck} className="max-w-72" />
                ),
            },
            {
                id: 'contact',
                header: 'Contact',
                cell: ({ row }) =>
                    row.original.contactName || row.original.phone || row.original.email ? (
                        <div className="grid text-sm leading-5">
                            <span>{row.original.contactName ?? row.original.email ?? row.original.phone}</span>
                            <span className="text-muted-foreground text-xs">
                                {row.original.contactName ? (row.original.phone ?? row.original.email) : null}
                            </span>
                        </div>
                    ) : (
                        dash
                    ),
            },
            { id: 'town', header: 'Town', enableSorting: true, cell: ({ row }) => row.original.town ?? dash },
            {
                id: 'terms',
                header: 'Terms',
                cell: ({ row }) => (
                    <div className="grid text-sm leading-5">
                        <span>{row.original.terms ?? '—'}</span>
                        {row.original.orderMethod && (
                            <span className="text-muted-foreground text-xs">Order by {row.original.orderMethod.toLowerCase()}</span>
                        )}
                    </div>
                ),
            },
            {
                id: 'status',
                header: 'Status',
                cell: ({ row }) => <StatusBadge status={row.original.isActive ? 'active' : 'inactive'} />,
            },
            {
                id: 'actions',
                header: () => <span className="sr-only">Actions</span>,
                cell: ({ row }) =>
                    canEdit ? (
                        <RowActions
                            label={`Actions for ${row.original.name}`}
                            actions={[
                                { label: 'Edit', icon: Pencil, href: route('app.suppliers.edit', row.original.id) },
                                { label: 'Remove', icon: Trash2, destructive: true, onSelect: () => setRemoving(row.original) },
                            ]}
                        />
                    ) : null,
                meta: { mobile: 'actions' },
            },
        ],
        [canEdit],
    );

    return (
        <AppLayout>
            <Head title="Suppliers" />

            <PageHeader
                title="Suppliers"
                description="Who you buy from. Every till gets this list at its next sync, for orders and deliveries."
                actions={
                    canEdit && (
                        <Button asChild>
                            <Link href={route('app.suppliers.create')}>
                                <Plus />
                                Add supplier
                            </Link>
                        </Button>
                    )
                }
            />

            {!canEdit && <ReadOnlyNotice what="Suppliers" />}

            <StatGrid columns={3}>
                <StatCard label="Suppliers" value={number.format(counts.all)} icon={Truck} tone="neutral" />
                <StatCard label="Active" value={number.format(counts.active)} hint="Shown on the tills" icon={CheckCircle2} tone="success" />
                <StatCard label="Inactive" value={number.format(counts.inactive)} hint="Kept for past orders" icon={CircleOff} tone="neutral" />
            </StatGrid>

            <DataTable
                columns={columns}
                data={suppliers.data}
                meta={suppliers.meta}
                only={ONLY}
                searchPlaceholder="Search by name, code, contact, town or account"
                filters={
                    <FilterSelect
                        value={filters.status === 'all' ? null : filters.status}
                        onChange={(status) => update({ status, page: 1 })}
                        all="Any status"
                        options={STATUS_OPTIONS}
                        label="Filter by status"
                    />
                }
                getRowId={(row) => row.id}
                onRowClick={canEdit ? (row) => router.visit(route('app.suppliers.edit', row.id)) : undefined}
                empty={
                    filtered ? undefined : (
                        <EmptyState
                            icon={Truck}
                            title="No suppliers yet"
                            body="Add the wholesalers and reps you buy from. Suppliers added on a till appear here after it syncs."
                            action={
                                canEdit && (
                                    <Button asChild>
                                        <Link href={route('app.suppliers.create')}>
                                            <Plus />
                                            Add supplier
                                        </Link>
                                    </Button>
                                )
                            }
                        />
                    )
                }
            />

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={`Remove ${removing?.name ?? 'this supplier'}?`}
                description="It disappears from every till at the next sync. Past orders, deliveries and invoices keep it. To stop using it for a while, mark it inactive instead."
                confirmLabel="Remove supplier"
                destructive
                onConfirm={() =>
                    new Promise((resolve) =>
                        router.delete(route('app.suppliers.destroy', removing?.id ?? ''), {
                            preserveScroll: true,
                            onFinish: () => {
                                setRemoving(null);
                                resolve(null);
                            },
                        }),
                    )
                }
            />
        </AppLayout>
    );
}
