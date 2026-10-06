import { FilterSelect, ReadOnlyNotice, STATUS_OPTIONS } from '@/components/app/setup/fields';
import { StaffTabs } from '@/components/app/setup/till-list-tabs';
import { type StaffIndexProps, type StaffRow } from '@/components/app/setup/types';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { EntityCell } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
import { RowActions } from '@/components/shared/row-actions';
import { StatusBadge, StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatMoney } from '@/lib/country';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { KeyRound, Nfc, Pencil, Plus, UserCog } from 'lucide-react';
import { useMemo } from 'react';

const ONLY = ['staff', 'filters', 'counts'];

function SignIn({ row }: { row: StaffRow }) {
    return (
        <div className="flex flex-wrap gap-1.5">
            <StatusPill tone={row.hasPin ? 'success' : 'warning'} className="gap-1">
                <KeyRound className="size-3" aria-hidden />
                {row.hasPin ? 'PIN set' : row.pinNeedsReset ? 'PIN needs resetting' : 'No PIN'}
            </StatusPill>
            {row.hasFob && (
                <StatusPill tone="info" className="gap-1">
                    <Nfc className="size-3" aria-hidden />
                    Fob
                </StatusPill>
            )}
        </div>
    );
}

export default function Staff({ staff, filters, counts, options, canEdit }: StaffIndexProps) {
    const { update } = useTableQuery({ only: ONLY });
    const filtered = Boolean(staff.meta.search || filters.role || filters.branch) || filters.status !== 'all';

    const columns = useMemo<ColumnDef<StaffRow>[]>(
        () => [
            {
                id: 'name',
                header: 'Name',
                enableSorting: true,
                cell: ({ row }) => <EntityCell name={row.original.name} subline={row.original.role ?? 'No role'} />,
            },
            { id: 'signin', header: 'Signs in with', cell: ({ row }) => <SignIn row={row.original} /> },
            {
                id: 'shops',
                header: 'Works at',
                cell: ({ row }) =>
                    row.original.branches.length ? row.original.branches.join(', ') : <span className="text-muted-foreground">Any shop</span>,
            },
            {
                id: 'rate',
                header: 'Hourly rate',
                cell: ({ row }) => (Number(row.original.ratePerHour) > 0 ? formatMoney(row.original.ratePerHour) : '—'),
                meta: { align: 'right' },
            },
            { id: 'status', header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.isActive ? 'active' : 'inactive'} /> },
            {
                id: 'actions',
                header: () => <span className="sr-only">Actions</span>,
                cell: ({ row }) => (
                    <RowActions
                        label={`Actions for ${row.original.name}`}
                        actions={[{ label: canEdit ? 'Edit' : 'View', icon: Pencil, href: route('app.staff.edit', row.original.id) }]}
                    />
                ),
            },
        ],
        [canEdit],
    );

    return (
        <AppLayout>
            <Head title="Staff" />
            <PageHeader
                title="Staff"
                description={`The people who sign in to your tills, with their PIN or fob. ${counts.active} of ${counts.all} active.`}
                actions={
                    canEdit && (
                        <Button asChild>
                            <Link href={route('app.staff.create')}>
                                <Plus />
                                Add staff member
                            </Link>
                        </Button>
                    )
                }
                tabs={<StaffTabs active="staff" />}
            />

            {!canEdit && <ReadOnlyNotice what="Till staff" />}

            <DataTable
                columns={columns}
                data={staff.data}
                meta={staff.meta}
                only={ONLY}
                searchPlaceholder="Search by name"
                filters={
                    <>
                        <FilterSelect
                            value={filters.role}
                            onChange={(role) => update({ role, page: 1 })}
                            all="Any role"
                            options={options.roles}
                            label="Filter by till role"
                        />
                        {options.branches.length > 1 && (
                            <FilterSelect
                                value={filters.branch}
                                onChange={(branch) => update({ branch, page: 1 })}
                                all="All shops"
                                options={options.branches}
                                label="Filter by shop"
                            />
                        )}
                        <FilterSelect
                            value={filters.status === 'all' ? null : filters.status}
                            onChange={(status) => update({ status, page: 1 })}
                            all="Any status"
                            options={STATUS_OPTIONS}
                            label="Filter by status"
                            width="sm:w-36"
                        />
                    </>
                }
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(route('app.staff.edit', row.id))}
                empty={
                    filtered ? undefined : (
                        <EmptyState
                            icon={UserCog}
                            title="No till staff yet"
                            body="Staff set up on a till appear here after its first sync. Add someone here and they can sign in on every till."
                            action={
                                canEdit && (
                                    <Button asChild>
                                        <Link href={route('app.staff.create')}>Add staff member</Link>
                                    </Button>
                                )
                            }
                        />
                    )
                }
            />
        </AppLayout>
    );
}
