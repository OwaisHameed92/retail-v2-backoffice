import { formatDateTime, money, number, Qty, signedMoney, signedQty } from '@/components/app/stock/format';
import { TAKE_SCOPES, TakeStatusPill } from '@/components/app/stock/take-status';
import { type TakeLine, type TakeProps } from '@/components/app/stock/types';
import { DataTable, type TableParams, useTableQuery } from '@/components/shared/data-table';
import { DescriptionList } from '@/components/shared/description-list';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { PageTabs } from '@/components/shared/page-tabs';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusPill } from '@/components/shared/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { ClipboardCheck, ClipboardList, TrendingDown, TrendingUp } from 'lucide-react';
import { useMemo } from 'react';

const ONLY = ['lines', 'view'];
const dash = <span className="text-muted-foreground">—</span>;

/** One stock take (module 5.1), read only: every line as the till counted it, variances as the till worked them out. */
export default function StockTake({ take, lines, view }: TakeProps) {
    const { update, loading } = useTableQuery({ only: ONLY });
    const change = (params: TableParams) => {
        const { search, ...rest } = params;
        void search;
        update(rest);
    };

    const columns = useMemo<ColumnDef<TakeLine>[]>(
        () => [
            {
                id: 'product',
                header: 'Product',
                meta: { mobile: 'title' },
                cell: ({ row }) => (
                    <div className="grid max-w-72 leading-5">
                        <Link href={route('app.stock.products.show', row.original.productId)} className="truncate font-medium hover:underline">
                            {row.original.name}
                        </Link>
                        {row.original.recount && <span className="text-warning-foreground text-xs">Recount asked for</span>}
                    </div>
                ),
            },
            {
                id: 'expected',
                header: 'Expected',
                meta: { align: 'right', mobile: 'field' },
                cell: ({ row }) => <Qty value={row.original.expected} />,
            },
            {
                id: 'counted',
                header: 'Counted',
                meta: { align: 'right', mobile: 'field' },
                cell: ({ row }) =>
                    row.original.counted === null ? (
                        <StatusPill tone="neutral">Not counted</StatusPill>
                    ) : (
                        <Qty value={row.original.counted} className="font-medium" />
                    ),
            },
            {
                id: 'varianceQty',
                header: 'Difference',
                meta: { align: 'right', mobile: 'field' },
                cell: ({ row }) => <Qty value={row.original.varianceQty} signed />,
            },
            {
                id: 'unitCost',
                header: 'Unit cost',
                meta: { align: 'right', mobile: 'hidden' },
                cell: ({ row }) => <span className="tabular-nums">{money(row.original.unitCost)}</span>,
            },
            {
                id: 'varianceCost',
                header: 'At cost',
                meta: { align: 'right', mobile: 'aside' },
                cell: ({ row }) =>
                    Number(row.original.varianceCost) === 0 ? (
                        dash
                    ) : (
                        <span
                            className={`tabular-nums ${Number(row.original.varianceCost) < 0 ? 'text-danger-foreground' : 'text-success-foreground'}`}
                        >
                            {signedMoney(row.original.varianceCost)}
                        </span>
                    ),
            },
            {
                id: 'by',
                header: 'Counted by',
                meta: { mobile: 'hidden' },
                cell: ({ row }) =>
                    row.original.countedBy || row.original.countedAt ? (
                        <div className="grid text-sm leading-5">
                            <span>{row.original.countedBy ?? 'Till user'}</span>
                            <span className="text-muted-foreground text-xs">{formatDateTime(row.original.countedAt)}</span>
                        </div>
                    ) : (
                        dash
                    ),
            },
        ],
        [],
    );

    const tab = (v: string | undefined) => route('app.stock.takes.show', { take: take.id, ...(v ? { view: v } : {}) });

    return (
        <AppLayout>
            <Head title={`Stock take ${take.reference || take.name}`} />

            <PageHeader
                title={take.name || take.reference || 'Stock take'}
                back={{ href: route('app.stock.takes.index'), label: 'Stock takes' }}
                status={<TakeStatusPill status={take.status} />}
                description={[take.reference, take.shop, `Started ${formatDateTime(take.startedAt)}`].filter(Boolean).join(' · ')}
            />

            <StatGrid columns={4}>
                <StatCard
                    label="Counted"
                    value={`${number(take.counted)} of ${number(take.lines)}`}
                    hint={take.recount > 0 ? `${number(take.recount)} to recount` : 'Lines'}
                    icon={ClipboardList}
                />
                <StatCard
                    label="Variance at cost"
                    value={signedMoney(take.varianceCost)}
                    hint={`${signedQty(take.varianceQty)} units`}
                    icon={ClipboardCheck}
                    tone={Number(take.varianceCost) < 0 ? 'danger' : 'success'}
                />
                <StatCard label="Found (over)" value={signedMoney(take.gains)} hint="Counted more than expected" icon={TrendingUp} tone="success" />
                <StatCard
                    label="Missing (short)"
                    value={signedMoney(take.losses)}
                    hint={`Expected stock worth ${money(take.snapshotValue)}`}
                    icon={TrendingDown}
                    tone="danger"
                />
            </StatGrid>

            <div className="grid gap-4 lg:grid-cols-3 lg:items-start">
                <div className="grid gap-4 lg:col-span-2">
                    <PageTabs
                        label="Lines"
                        tabs={[
                            { label: 'Every line', href: tab(undefined), active: view === 'all', count: take.lines },
                            { label: 'Differences only', href: tab('variances'), active: view === 'variances' },
                        ]}
                    />
                    <DataTable
                        columns={columns}
                        data={lines.data}
                        meta={lines.meta}
                        onChange={change}
                        searchable={false}
                        getRowId={(row) => row.id}
                        loading={loading}
                        empty={
                            <EmptyState
                                icon={ClipboardList}
                                title={view === 'variances' ? 'No differences' : 'No lines yet'}
                                body={
                                    view === 'variances'
                                        ? 'Every counted line matched what was expected.'
                                        : 'Lines appear once the till syncs the count.'
                                }
                            />
                        }
                    />
                </div>
                <SectionCard title="Details">
                    <DescriptionList
                        layout="rows"
                        items={[
                            { label: 'Shop', value: take.shop },
                            { label: 'What was counted', value: TAKE_SCOPES[take.scope ?? ''] ?? '—' },
                            { label: 'Started by', value: take.startedBy ?? '—' },
                            {
                                label: 'Approved',
                                value: take.approvedAt ? `${formatDateTime(take.approvedAt)}${take.approvedBy ? ` · ${take.approvedBy}` : ''}` : '—',
                            },
                            ...(take.cancelledAt ? [{ label: 'Cancelled', value: formatDateTime(take.cancelledAt) }] : []),
                            { label: 'Blind count', value: take.isBlind ? 'Yes (staff did not see the expected quantity)' : 'No' },
                            { label: 'Not counted', value: take.countUncountedAsZero ? 'Treated as zero' : 'Left as they were' },
                            ...(take.note ? [{ label: 'Note', value: take.note }] : []),
                        ]}
                    />
                </SectionCard>
            </div>
        </AppLayout>
    );
}
