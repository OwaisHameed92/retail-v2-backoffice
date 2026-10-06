import { newsColumns } from '@/components/app/news/columns';
import { KIND_LABELS, money, NewsTabs, percent, statusText } from '@/components/app/news/format';
import { type NewsIndexProps, type NewsKind, type NewsRow } from '@/components/app/news/types';
import { FilterSelect } from '@/components/app/setup/fields';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/country';
import { Head, Link, router } from '@inertiajs/react';
import { Info, Newspaper, Plus, Ticket, Truck, Undo2, type LucideIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

const ONLY = ['rows', 'filters', 'stats', 'tabs'];

const COPY: Record<NewsKind, { icon: LucideIcon; description: string; search: string; empty: string }> = {
    titles: {
        icon: Newspaper,
        description:
            'The papers and magazines your tills sell, for every shop or one shop, with their wholesaler and cover price. Delivery rounds and customer news accounts are not in the till yet.',
        search: 'Search by title, publisher or barcode',
        empty: 'Add the papers and magazines you sell. Each shop’s tills get them at their next sync.',
    },
    deliveries: {
        icon: Truck,
        description: 'News deliveries booked in at the shops, with copies sold and sent back. Kept at the shop, so read only here.',
        search: 'Search by wholesaler or notes',
        empty: 'News deliveries booked in on a till appear here after it syncs.',
    },
    returns: {
        icon: Undo2,
        description: 'Unsold copies sent back to the wholesaler and whether the credit has come through.',
        search: 'Search by wholesaler or notes',
        empty: 'Returns recorded on a till appear here after it syncs.',
    },
    vouchers: {
        icon: Ticket,
        description: 'Subscription and home-delivery vouchers taken at the tills, and whether they have been claimed back.',
        search: 'Search by voucher code or title',
        empty: 'Vouchers taken on a till appear here after it syncs.',
    },
};

function statValue(format: string, value: string): string {
    if (format === 'money') {
        return money(value);
    }

    return format === 'percent' ? percent(value) : formatNumber(Number(value));
}

/** Newspapers (module 5.8): titles (edited here), and the shops' deliveries, returns and vouchers (read only). */
export default function NewsIndex(props: NewsIndexProps) {
    const { kind, rows, filters, statuses, stats, tabs, shops, suppliers, oneShop, can } = props;
    const { update } = useTableQuery({ only: ONLY });
    const [archiving, setArchiving] = useState<NewsRow | null>(null);
    const [processing, setProcessing] = useState(false);
    const copy = COPY[kind];
    const showShop = !oneShop && shops.length > 1;
    const columns = useMemo(
        () =>
            newsColumns(kind, showShop, can.manage, {
                onArchive: setArchiving,
                onRestore: (row) => router.post(route('app.news.titles.restore', row.id), {}, { preserveScroll: true }),
            }),
        [kind, showShop, can.manage],
    );
    const filtered = Boolean(rows.meta.search) || Boolean((!oneShop && filters.shop) || filters.supplier || filters.status);
    const newTitle = can.manage && kind === 'titles' && (
        <Button asChild>
            <Link href={route('app.news.titles.create')}>
                <Plus />
                Add a title
            </Link>
        </Button>
    );

    return (
        <AppLayout>
            <Head title={`${KIND_LABELS[kind]} · Newspapers`} />

            <PageHeader title="Newspapers" description={copy.description} actions={newTitle} tabs={<NewsTabs current={kind} counts={tabs} />} />

            {oneShop && (
                <Alert variant="info">
                    <Info />
                    <AlertDescription>
                        You are seeing {shops[0]?.name ?? 'your shop'} only
                        {kind === 'titles' ? ', with the titles every shop sells. Only a user of every shop can change those.' : '.'}
                    </AlertDescription>
                </Alert>
            )}

            <StatGrid columns={4}>
                {stats.map((stat) => (
                    <StatCard
                        key={stat.label}
                        label={stat.label}
                        value={statValue(stat.format, stat.value)}
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
                        {showShop && (
                            <FilterSelect
                                value={filters.shop}
                                onChange={(shop) => update({ shop, page: 1 })}
                                all="Every shop"
                                options={shops.map((s) => ({ value: s.id, label: s.name }))}
                                label="Filter by shop"
                            />
                        )}
                        {kind !== 'vouchers' && (
                            <FilterSelect
                                value={filters.supplier}
                                onChange={(supplier) => update({ supplier, page: 1 })}
                                all="Every wholesaler"
                                options={suppliers.map((s) => ({ value: s.id, label: s.name }))}
                                label="Filter by wholesaler"
                            />
                        )}
                        <FilterSelect
                            value={filters.status}
                            onChange={(status) => update({ status, page: 1 })}
                            all="Any status"
                            options={statuses.map((s) => ({ value: s, label: statusText(s) }))}
                            label="Filter by status"
                            width="sm:w-40"
                        />
                    </div>
                }
                getRowId={(row) => row.id}
                onRowClick={
                    kind === 'deliveries' || kind === 'returns'
                        ? (row) => router.visit(route('app.news.deliveries.show', row.id))
                        : kind === 'titles' && can.manage
                          ? (row) => row.canEdit && router.visit(route('app.news.titles.edit', row.id))
                          : undefined
                }
                empty={
                    filtered ? undefined : (
                        <EmptyState icon={copy.icon} title={`No ${KIND_LABELS[kind].toLowerCase()} yet`} body={copy.empty} action={newTitle} />
                    )
                }
            />

            <ConfirmDialog
                open={archiving !== null}
                onOpenChange={(open) => !open && setArchiving(null)}
                title={`Archive ${archiving?.name ?? 'this title'}?`}
                description="The tills stop offering it at their next sync. Its deliveries, returns and vouchers stay, and you can put it back on sale later."
                confirmLabel="Archive"
                destructive
                processing={processing}
                onConfirm={() => {
                    if (!archiving) {
                        return;
                    }
                    router.post(
                        route('app.news.titles.archive', archiving.id),
                        {},
                        {
                            preserveScroll: true,
                            onStart: () => setProcessing(true),
                            onFinish: () => {
                                setProcessing(false);
                                setArchiving(null);
                            },
                        },
                    );
                }}
            />
        </AppLayout>
    );
}
