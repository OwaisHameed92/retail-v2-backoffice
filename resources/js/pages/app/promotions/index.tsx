import { FilterSelect } from '@/components/app/setup/fields';
import { formatDay, OFFER_TONES } from '@/components/app/pricing/format';
import { type PromotionIndexProps, type PromotionRow } from '@/components/app/pricing/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { RowActions } from '@/components/shared/row-actions';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { CalendarClock, CircleStop, Eye, Info, Pencil, Plus, Store, Tag, TicketPercent } from 'lucide-react';
import { useMemo, useState } from 'react';

const ONLY = ['promotions', 'filters', 'counts'];
const number = new Intl.NumberFormat('en-GB');
const STATUS_OPTIONS = [
    { value: 'live', label: 'Live now' },
    { value: 'scheduled', label: 'Scheduled' },
    { value: 'ended', label: 'Ended' },
];

export default function Promotions({ promotions, filters, shops, counts, restrictedShop, canCreate }: PromotionIndexProps) {
    const { update } = useTableQuery({ only: ONLY });
    const [ending, setEnding] = useState<PromotionRow | null>(null);
    const filtered = Boolean(promotions.meta.search) || filters.status !== 'all' || filters.shop !== 'all';

    const columns = useMemo<ColumnDef<PromotionRow>[]>(
        () => [
            {
                id: 'name',
                header: 'Offer',
                enableSorting: true,
                cell: ({ row }) => (
                    <div className="grid max-w-80 leading-5">
                        <span className="font-medium">{row.original.name}</span>
                        <span className="text-muted-foreground text-xs">
                            {row.original.deal} · {row.original.on}
                        </span>
                    </div>
                ),
            },
            { id: 'type', header: 'Type', cell: ({ row }) => <Badge variant="secondary">{row.original.typeLabel}</Badge> },
            {
                id: 'shop',
                header: 'Where',
                cell: ({ row }) => (row.original.branchId === null ? <span>All shops</span> : <span className="inline-flex items-center gap-1.5"><Store className="size-3.5" aria-hidden />{row.original.shop}</span>),
            },
            {
                id: 'effective_from',
                header: 'Dates',
                enableSorting: true,
                cell: ({ row }) => (
                    <div className="grid text-sm leading-5 whitespace-nowrap">
                        <span>
                            {formatDay(row.original.from)} – {row.original.to ? formatDay(row.original.to) : 'no end'}
                        </span>
                        {row.original.times && <span className="text-muted-foreground text-xs">{row.original.times}</span>}
                    </div>
                ),
            },
            { id: 'status', header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.status} tones={OFFER_TONES} label={row.original.status === 'live' ? 'Live' : undefined} /> },
            {
                id: 'actions',
                header: () => <span className="sr-only">Actions</span>,
                cell: ({ row }) => (
                    <RowActions
                        label={`Actions for ${row.original.name}`}
                        actions={[
                            { label: row.original.canEdit ? 'Edit' : 'View', icon: row.original.canEdit ? Pencil : Eye, href: route('app.promotions.edit', row.original.id) },
                            ...(row.original.canEdit && row.original.status !== 'ended'
                                ? [{ label: 'End now', icon: CircleStop, destructive: true, onSelect: () => setEnding(row.original) }]
                                : []),
                        ]}
                    />
                ),
                meta: { mobile: 'actions' },
            },
        ],
        [],
    );

    const add = canCreate && (
        <Button asChild>
            <Link href={route('app.promotions.create')}>
                <Plus />
                Add offer
            </Link>
        </Button>
    );

    return (
        <AppLayout>
            <Head title="Promotions" />
            <PageHeader title="Promotions" description="Offers and standing discounts. Each till runs every-shop offers and its own shop's." actions={add} />

            {restrictedShop !== null && (
                <Alert variant="info">
                    <Info />
                    <AlertDescription>You can add and change offers for your own shop. Offers for every shop are shown but read-only.</AlertDescription>
                </Alert>
            )}

            <StatGrid columns={4}>
                <StatCard label="Live now" value={number.format(counts.live)} icon={TicketPercent} tone="success" />
                <StatCard label="Scheduled" value={number.format(counts.scheduled)} icon={CalendarClock} tone="neutral" />
                <StatCard label="Shop-only live" value={number.format(counts.shopOnly)} hint="One shop's own offers" icon={Store} tone="primary" />
                <StatCard label="Ended" value={number.format(counts.ended)} icon={CircleStop} tone="neutral" />
            </StatGrid>

            <DataTable
                columns={columns}
                data={promotions.data}
                meta={promotions.meta}
                only={ONLY}
                searchPlaceholder="Search by name or coupon code"
                filters={
                    <>
                        <FilterSelect value={filters.status === 'all' ? null : filters.status} onChange={(status) => update({ status, page: 1 })} all="Any status" options={STATUS_OPTIONS} label="Filter by status" />
                        <FilterSelect
                            value={filters.shop === 'all' ? null : filters.shop}
                            onChange={(shop) => update({ shop, page: 1 })}
                            all="Any shop"
                            options={[{ value: 'every', label: 'All-shop offers' }, ...shops]}
                            label="Filter by shop"
                        />
                    </>
                }
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(route('app.promotions.edit', row.id))}
                empty={filtered ? undefined : <EmptyState icon={Tag} title="No offers yet" body="Add a % off, multi-buy or meal deal. Offers made at a till appear here after it syncs." action={add} />}
            />

            <ConfirmDialog
                open={ending !== null}
                onOpenChange={(open) => !open && setEnding(null)}
                title={`End ${ending?.name ?? 'this offer'} now?`}
                description="Tills stop it at their next sync. It stays in the list as ended, and you can switch it on again from its form."
                confirmLabel="End offer"
                destructive
                onConfirm={() =>
                    new Promise((resolve) =>
                        router.post(route('app.promotions.end', ending?.id ?? ''), {}, { preserveScroll: true, onFinish: () => resolve(setEnding(null)) }),
                    )
                }
            />
        </AppLayout>
    );
}
