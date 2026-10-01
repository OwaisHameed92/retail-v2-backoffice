import { purchasingColumns } from '@/components/app/purchasing/columns';
import { documentHref, KIND_LABELS, money, PurchasingTabs, statusText } from '@/components/app/purchasing/format';
import { type PurchasingIndexProps, type PurchasingKind } from '@/components/app/purchasing/types';
import { FilterSelect } from '@/components/app/setup/fields';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    Banknote,
    ClipboardList,
    FileText,
    Info,
    PackageCheck,
    Percent,
    Plus,
    ReceiptText,
    ShoppingCart,
    Truck,
    Undo2,
    type LucideIcon,
} from 'lucide-react';
import { useMemo } from 'react';

const ONLY = ['rows', 'filters', 'stats', 'tabs'];
const count = new Intl.NumberFormat('en-GB');

const COPY: Record<PurchasingKind, { icon: LucideIcon; description: string; search: string; empty: string }> = {
    orders: {
        icon: ClipboardList,
        description: 'Orders your shops placed with suppliers, and head-office orders drafted here for a shop.',
        search: 'Search by reference, supplier or notes',
        empty: 'Orders placed on a till appear here after it syncs. You can also draft an order for a shop here.',
    },
    deliveries: {
        icon: PackageCheck,
        description: 'Goods booked in at the shops (GRNs), with anything that arrived damaged.',
        search: 'Search by delivery note, supplier or note',
        empty: 'Deliveries booked in on a till appear here after it syncs.',
    },
    invoices: {
        icon: FileText,
        description: 'Supplier invoices keyed in at the shops, matched to the delivery they are for.',
        search: 'Search by invoice number, supplier or notes',
        empty: 'Supplier invoices entered on a till appear here after it syncs.',
    },
    'credit-notes': {
        icon: ReceiptText,
        description: 'Credit from suppliers: returns credited, rebates settled and price corrections.',
        search: 'Search by credit note, supplier or reason',
        empty: 'Credit notes entered on a till appear here after it syncs.',
    },
    returns: {
        icon: Undo2,
        description: 'Goods sent back to suppliers. Each return goes from draft to sent, then credited or cancelled, at the shop.',
        search: 'Search by return, supplier or credit note',
        empty: 'Purchase returns made on a till appear here after it syncs.',
    },
    payments: {
        icon: Banknote,
        description: 'Payments made to suppliers, recorded at the shops.',
        search: 'Search by reference, supplier or notes',
        empty: 'Supplier payments recorded on a till appear here after it syncs.',
    },
    rebates: {
        icon: Percent,
        description: 'Rebate agreements with suppliers and what your shops have earned against them.',
        search: 'Search by agreement or supplier',
        empty: 'Rebate agreements appear here once they are set up.',
    },
};

/** Purchasing lists (module 5.2): one tab per kind of document, read only. Head-office orders can be drafted from Orders. */
export default function PurchasingIndex(props: PurchasingIndexProps) {
    const { kind, rows, filters, statuses, stats, tabs, shops, suppliers, oneShop, can } = props;
    const { update } = useTableQuery({ only: ONLY });
    const copy = COPY[kind];
    const showShop = !oneShop && shops.length > 1 && kind !== 'rebates';
    const columns = useMemo(() => purchasingColumns(kind, showShop), [kind, showShop]);
    const filtered = Boolean(rows.meta.search) || Boolean((!oneShop && filters.shop) || filters.supplier || filters.status || filters.origin);
    const opens = kind !== 'payments' && kind !== 'rebates';
    const newOrder = can.manage && kind === 'orders' && (
        <Button asChild>
            <Link href={route('app.purchasing.orders.create')}>
                <Plus />
                New head-office order
            </Link>
        </Button>
    );
    const actions = kind === 'orders' && (
        <div className="flex flex-wrap gap-2">
            <Button variant="outline" asChild>
                <Link href={route('app.purchasing.suggestions.index')}>
                    <ShoppingCart />
                    Reorder suggestions
                </Link>
            </Button>
            {newOrder}
        </div>
    );

    return (
        <AppLayout>
            <Head title={`${KIND_LABELS[kind]} · Purchasing`} />

            <PageHeader title="Purchasing" description={copy.description} actions={actions} tabs={<PurchasingTabs current={kind} counts={tabs} />} />

            {oneShop && (
                <Alert variant="info">
                    <Info />
                    <AlertDescription>You are seeing {shops[0]?.name ?? 'your shop'} only.</AlertDescription>
                </Alert>
            )}

            <StatGrid columns={stats.length === 3 ? 3 : 4}>
                {stats.map((stat) => (
                    <StatCard
                        key={stat.label}
                        label={stat.label}
                        value={stat.format === 'money' ? money(stat.value) : count.format(Number(stat.value))}
                        hint={stat.hint}
                        tone={stat.tone}
                        icon={copy.icon}
                    />
                ))}
            </StatGrid>

            <DataTable
                columns={columns}
                data={rows.data}
                meta={rows.meta}
                only={ONLY}
                searchPlaceholder={copy.search}
                filters={
                    <div className="flex flex-col gap-2 sm:flex-row">
                        {!oneShop && shops.length > 1 && kind !== 'rebates' && (
                            <FilterSelect
                                value={filters.shop}
                                onChange={(shop) => update({ shop, page: 1 })}
                                all="Every shop"
                                options={shops.map((s) => ({ value: s.id, label: s.name }))}
                                label="Filter by shop"
                            />
                        )}
                        <FilterSelect
                            value={filters.supplier}
                            onChange={(supplier) => update({ supplier, page: 1 })}
                            all="Every supplier"
                            options={suppliers.map((s) => ({ value: s.id, label: s.name }))}
                            label="Filter by supplier"
                        />
                        {statuses.length > 0 && (
                            <FilterSelect
                                value={filters.status}
                                onChange={(status) => update({ status, page: 1 })}
                                all="Any status"
                                options={statuses.map((s) => ({ value: s, label: statusText(s) }))}
                                label="Filter by status"
                                width="sm:w-40"
                            />
                        )}
                        {kind === 'orders' && (
                            <FilterSelect
                                value={filters.origin}
                                onChange={(origin) => update({ origin, page: 1 })}
                                all="Shop and head office"
                                options={[
                                    { value: 'branch', label: 'Placed by the shop' },
                                    { value: 'headOffice', label: 'From head office' },
                                ]}
                                label="Filter by who placed it"
                            />
                        )}
                    </div>
                }
                getRowId={(row) => row.id}
                onRowClick={opens ? (row) => router.visit(documentHref(kind, row.id) ?? '') : undefined}
                empty={
                    filtered ? undefined : (
                        <EmptyState
                            icon={kind === 'orders' ? Truck : copy.icon}
                            title={`No ${KIND_LABELS[kind].toLowerCase()} yet`}
                            body={copy.empty}
                            action={newOrder}
                        />
                    )
                }
            />
        </AppLayout>
    );
}
