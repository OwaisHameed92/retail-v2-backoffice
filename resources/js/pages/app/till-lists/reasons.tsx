import { FilterSelect, ReadOnlyNotice } from '@/components/app/setup/fields';
import { ReasonDialog } from '@/components/app/setup/reason-dialog';
import { TillListTabs } from '@/components/app/setup/till-list-tabs';
import { type ReasonIndexProps, type ReasonRow } from '@/components/app/setup/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { RowActions } from '@/components/shared/row-actions';
import { StatusBadge, StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { ListChecks, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

const ONLY = ['reasons', 'filters', 'counts'];

export default function Reasons({ reasons, filters, counts, types, canEdit }: ReasonIndexProps) {
    const { update } = useTableQuery({ only: ONLY });
    const [editing, setEditing] = useState<{ row: ReasonRow | null; key: number } | null>(null);
    const [removing, setRemoving] = useState<ReasonRow | null>(null);
    const open = (row: ReasonRow | null) => setEditing({ row, key: Date.now() });

    const columns = useMemo<ColumnDef<ReasonRow>[]>(
        () => [
            {
                id: 'text',
                header: 'Reason',
                enableSorting: true,
                cell: ({ row }) => <span className="font-medium">{row.original.text}</span>,
                meta: { mobile: 'title' },
            },
            {
                id: 'type',
                header: 'Asked for',
                enableSorting: true,
                cell: ({ row }) => <StatusPill tone="info">{row.original.typeLabel}</StatusPill>,
            },
            {
                id: 'position',
                header: 'Order',
                enableSorting: true,
                cell: ({ row }) => <span className="tabular-nums">{row.original.position}</span>,
                meta: { align: 'right' },
            },
            {
                id: 'account_code',
                header: 'Account code',
                cell: ({ row }) =>
                    row.original.account_code ? (
                        <span className="font-mono text-xs">{row.original.account_code}</span>
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    ),
            },
            { id: 'status', header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.is_active ? 'active' : 'inactive'} /> },
            {
                id: 'actions',
                header: () => <span className="sr-only">Actions</span>,
                cell: ({ row }) =>
                    canEdit ? (
                        <RowActions
                            label={`Actions for ${row.original.text}`}
                            actions={[
                                { label: 'Edit', icon: Pencil, onSelect: () => open(row.original) },
                                { label: 'Remove', icon: Trash2, destructive: true, onSelect: () => setRemoving(row.original) },
                            ]}
                        />
                    ) : null,
            },
        ],
        [canEdit],
    );

    return (
        <AppLayout>
            <Head title="Reasons" />
            <PageHeader
                title="Payment types and reasons"
                description={`The reasons staff choose for refunds, voids, discounts, no sales, wastage and more. ${counts.active} of ${counts.all} active.`}
                actions={
                    canEdit && (
                        <Button onClick={() => open(null)}>
                            <Plus />
                            Add reason
                        </Button>
                    )
                }
                tabs={<TillListTabs active="reasons" />}
            />

            {!canEdit && <ReadOnlyNotice what="Reasons" />}

            <DataTable
                columns={columns}
                data={reasons.data}
                meta={reasons.meta}
                only={ONLY}
                searchPlaceholder="Search reasons or account codes"
                filters={
                    <FilterSelect
                        value={filters.type}
                        onChange={(type) => update({ type, page: 1 })}
                        all="Every type"
                        options={types}
                        label="Filter by when the till asks"
                        width="sm:w-52"
                    />
                }
                getRowId={(row) => row.id}
                onRowClick={canEdit ? (row) => open(row) : undefined}
                empty={
                    reasons.meta.search || filters.type ? undefined : (
                        <EmptyState
                            icon={ListChecks}
                            title="No reasons yet"
                            body="Your tills send their reason lists at their first sync. You can also add them here."
                            action={canEdit && <Button onClick={() => open(null)}>Add reason</Button>}
                        />
                    )
                }
            />

            {editing && (
                <ReasonDialog
                    key={editing.key}
                    row={editing.row}
                    types={types}
                    defaultType={filters.type}
                    open
                    onOpenChange={(o) => !o && setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(o) => !o && setRemoving(null)}
                title={`Remove "${removing?.text ?? 'this reason'}"?`}
                description="It disappears from every till at the next sync. Past records keep it. To hide it for now, mark it inactive instead."
                confirmLabel="Remove reason"
                destructive
                onConfirm={() =>
                    new Promise((resolve) =>
                        router.delete(route('app.reasons.destroy', removing?.id ?? ''), {
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
