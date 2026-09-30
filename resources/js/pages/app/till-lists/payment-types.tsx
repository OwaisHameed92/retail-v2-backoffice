import { ReadOnlyNotice } from '@/components/app/setup/fields';
import { PaymentTypeDialog } from '@/components/app/setup/payment-type-dialog';
import { TillListTabs } from '@/components/app/setup/till-list-tabs';
import { type PaymentTypeIndexProps, type PaymentTypeRow } from '@/components/app/setup/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { DataTable } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { EntityCell } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
import { RowActions } from '@/components/shared/row-actions';
import { StatusBadge, StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { CreditCard, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

const ONLY = ['paymentTypes', 'counts'];

function where(row: PaymentTypeRow): string {
    const places = [row.show_on_payment && 'Sales', row.show_on_refund && 'Refunds', row.show_on_customer_payment && 'Account payments'].filter(
        Boolean,
    );

    return places.length ? places.join(', ') : 'Hidden';
}

export default function PaymentTypes({ paymentTypes, counts, canEdit }: PaymentTypeIndexProps) {
    const [editing, setEditing] = useState<{ row: PaymentTypeRow | null; key: number } | null>(null);
    const [removing, setRemoving] = useState<PaymentTypeRow | null>(null);
    const open = (row: PaymentTypeRow | null) => setEditing({ row, key: Date.now() });

    const columns = useMemo<ColumnDef<PaymentTypeRow>[]>(
        () => [
            {
                id: 'position',
                header: '#',
                enableSorting: true,
                cell: ({ row }) => <span className="text-muted-foreground tabular-nums">{row.original.position}</span>,
                meta: { mobile: 'hidden' },
            },
            {
                id: 'name',
                header: 'Payment type',
                enableSorting: true,
                cell: ({ row }) => (
                    <EntityCell
                        name={row.original.name}
                        subline={[row.original.kind, row.original.system && "Till's own", row.original.shops > 1 && `One per shop (${row.original.shops})`]
                            .filter(Boolean)
                            .join(' · ')}
                        shape="square"
                        icon={CreditCard}
                    />
                ),
                meta: { mobile: 'title' },
            },
            { id: 'where', header: 'Shown for', cell: ({ row }) => where(row.original) },
            {
                id: 'drawer',
                header: 'Drawer',
                cell: ({ row }) => (
                    <StatusPill tone={row.original.opens_drawer ? 'info' : 'neutral'}>
                        {row.original.opens_drawer ? 'Opens' : 'Stays shut'}
                    </StatusPill>
                ),
            },
            { id: 'status', header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.is_active ? 'active' : 'inactive'} /> },
            {
                id: 'actions',
                header: () => <span className="sr-only">Actions</span>,
                cell: ({ row }) =>
                    canEdit ? (
                        <RowActions
                            label={`Actions for ${row.original.name}`}
                            actions={[
                                { label: 'Edit', icon: Pencil, onSelect: () => open(row.original) },
                                ...(row.original.system
                                    ? []
                                    : [{ label: 'Remove', icon: Trash2, destructive: true, onSelect: () => setRemoving(row.original) }]),
                            ]}
                        />
                    ) : null,
            },
        ],
        [canEdit],
    );

    return (
        <AppLayout>
            <Head title="Payment types" />
            <PageHeader
                title="Payment types and reasons"
                description={`The ways customers can pay on your tills. ${counts.active} of ${counts.all} active.`}
                actions={
                    canEdit && (
                        <Button onClick={() => open(null)}>
                            <Plus />
                            Add payment type
                        </Button>
                    )
                }
                tabs={<TillListTabs active="payment-types" />}
            />

            {!canEdit && <ReadOnlyNotice what="Payment types" />}

            <DataTable
                columns={columns}
                data={paymentTypes.data}
                meta={paymentTypes.meta}
                only={ONLY}
                searchPlaceholder="Search payment types"
                getRowId={(row) => row.id}
                onRowClick={canEdit ? (row) => open(row) : undefined}
                empty={
                    paymentTypes.meta.search ? undefined : (
                        <EmptyState
                            icon={CreditCard}
                            title="No payment types yet"
                            body="Your tills send their payment types at their first sync. You can also add them here."
                            action={canEdit && <Button onClick={() => open(null)}>Add payment type</Button>}
                        />
                    )
                }
            />

            {editing && <PaymentTypeDialog key={editing.key} row={editing.row} open onOpenChange={(o) => !o && setEditing(null)} />}

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(o) => !o && setRemoving(null)}
                title={`Remove ${removing?.name ?? 'this payment type'}?`}
                description={`It disappears from every till's pay screen at the next sync${removing && removing.shops > 1 ? `, in all ${removing.shops} shops` : ''}. Past sales keep it. To hide it for now, mark it inactive instead.`}
                confirmLabel="Remove payment type"
                destructive
                onConfirm={() =>
                    new Promise((resolve) =>
                        router.delete(route('app.payment-types.destroy', removing?.id ?? ''), {
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
