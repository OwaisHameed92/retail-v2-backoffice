import { BALANCE_OPTIONS, BalanceText, CONSENT_OPTIONS, money, number, STATUS_OPTIONS } from '@/components/app/customers/format';
import { type CustomerIndexProps, type CustomerRow } from '@/components/app/customers/types';
import { FilterSelect } from '@/components/app/setup/fields';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { EntityCell } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusBadge, StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Coins, MailCheck, Plus, Users, Wallet } from 'lucide-react';
import { useMemo } from 'react';

const ONLY = ['customers', 'filters', 'counts'];
const dash = <span className="text-muted-foreground">—</span>;

export default function Customers({ customers, filters, shops, counts, canEdit }: CustomerIndexProps) {
    const { update } = useTableQuery({ only: ONLY });
    const filtered =
        Boolean(customers.meta.search) ||
        filters.status !== 'active' ||
        filters.balance !== null ||
        filters.points !== null ||
        filters.consent !== null ||
        filters.shop !== null;

    const columns = useMemo<ColumnDef<CustomerRow>[]>(
        () => [
            {
                id: 'name',
                header: 'Customer',
                enableSorting: true,
                cell: ({ row }) => (
                    <EntityCell
                        name={row.original.name}
                        subline={row.original.cardNo ?? undefined}
                        monoSubline
                        className="max-w-72"
                        href={route('app.customers.show', row.original.id)}
                    />
                ),
            },
            {
                id: 'contact',
                header: 'Contact',
                cell: ({ row }) =>
                    row.original.phone || row.original.email ? (
                        <div className="grid max-w-64 text-sm leading-5">
                            <span className="truncate">{row.original.phone ?? row.original.email}</span>
                            {row.original.phone && row.original.email && (
                                <span className="text-muted-foreground truncate text-xs">{row.original.email}</span>
                            )}
                        </div>
                    ) : (
                        dash
                    ),
            },
            { id: 'tier', header: 'Tier', cell: ({ row }) => (row.original.tier ? <StatusPill tone="info">{row.original.tier}</StatusPill> : dash) },
            {
                id: 'balance',
                header: 'Balance',
                enableSorting: true,
                meta: { align: 'right' },
                cell: ({ row }) => (
                    <div className="grid justify-items-end leading-5">
                        <BalanceText balance={row.original.balance} />
                        {Number(row.original.creditLimit) > 0 && (
                            <span className="text-muted-foreground text-xs">Limit {money(row.original.creditLimit)}</span>
                        )}
                    </div>
                ),
            },
            {
                id: 'points',
                header: 'Points',
                enableSorting: true,
                meta: { align: 'right' },
                cell: ({ row }) => <span className="tabular-nums">{number(row.original.points)}</span>,
            },
            {
                id: 'status',
                header: 'Status',
                cell: ({ row }) =>
                    row.original.anonymised ? (
                        <StatusPill tone="neutral">Anonymised</StatusPill>
                    ) : (
                        <StatusBadge status={row.original.isActive ? 'active' : 'inactive'} />
                    ),
            },
        ],
        [],
    );

    return (
        <AppLayout>
            <Head title="Customers" />

            <PageHeader
                title="Customers"
                description="Everyone with an account or loyalty card, across all your shops. Balances and points add up every shop's till."
                actions={
                    canEdit && (
                        <Button asChild>
                            <Link href={route('app.customers.create')}>
                                <Plus />
                                Add customer
                            </Link>
                        </Button>
                    )
                }
            />

            <StatGrid columns={4}>
                <StatCard label="Customers" value={number(counts.all)} icon={Users} tone="neutral" />
                <StatCard
                    label="Owed to you"
                    value={money(counts.owed)}
                    hint={
                        `${number(counts.owing)} ${counts.owing === 1 ? 'customer owes' : 'customers owe'}` +
                        (Number(counts.creditHeld) > 0 ? ` · ${money(counts.creditHeld)} credit held` : '')
                    }
                    icon={Wallet}
                    tone="warning"
                    href={route('app.customers.index', { balance: 'owes', sort: 'balance', direction: 'desc' })}
                />
                <StatCard label="Points held" value={number(counts.points)} hint="Loyalty points not yet spent" icon={Coins} tone="primary" />
                <StatCard
                    label="Email opt-ins"
                    value={number(counts.emailConsent)}
                    hint="May receive marketing emails"
                    icon={MailCheck}
                    tone="success"
                    href={route('app.customers.index', { consent: 'email' })}
                />
            </StatGrid>

            <DataTable
                columns={columns}
                data={customers.data}
                meta={customers.meta}
                only={ONLY}
                searchPlaceholder="Search by name, phone, email or card number"
                filters={
                    <>
                        <FilterSelect
                            value={filters.balance}
                            onChange={(balance) => update({ balance, page: 1 })}
                            all="Any balance"
                            options={BALANCE_OPTIONS}
                            label="Filter by balance"
                        />
                        <FilterSelect
                            value={filters.points}
                            onChange={(points) => update({ points, page: 1 })}
                            all="Any points"
                            options={[{ value: 'has', label: 'Has points' }]}
                            label="Filter by points"
                            width="sm:w-36"
                        />
                        <FilterSelect
                            value={filters.consent}
                            onChange={(consent) => update({ consent, page: 1 })}
                            all="Any consent"
                            options={CONSENT_OPTIONS.map((o) => ({ value: o.value, label: `${o.label} opt-in` }))}
                            label="Filter by marketing consent"
                        />
                        {shops.length > 1 && (
                            <FilterSelect
                                value={filters.shop}
                                onChange={(shop) => update({ shop, page: 1 })}
                                all="Every shop"
                                options={shops}
                                label="Filter by shop used"
                            />
                        )}
                        <FilterSelect
                            value={filters.status === 'active' ? null : filters.status}
                            onChange={(status) => update({ status, page: 1 })}
                            all="Active"
                            options={STATUS_OPTIONS}
                            label="Filter by status"
                            width="sm:w-48"
                        />
                    </>
                }
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(route('app.customers.show', row.id))}
                empty={
                    filtered ? undefined : (
                        <EmptyState
                            icon={Users}
                            title="No customers yet"
                            body="Customers added at a till appear here after it syncs. You can also add them here and every till gets them."
                            action={
                                canEdit && (
                                    <Button asChild>
                                        <Link href={route('app.customers.create')}>
                                            <Plus />
                                            Add customer
                                        </Link>
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
