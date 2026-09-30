import { FilterSelect } from '@/components/app/setup/fields';
import { formatDateTime, formatDay, money, number, qty, Qty, StockTabs, withParams } from '@/components/app/stock/format';
import { StockFilters } from '@/components/app/stock/stock-filters';
import { type BatchRow, type ExpiryProps } from '@/components/app/stock/types';
import { DataTable, type TableParams, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusPill, type StatusTone } from '@/components/shared/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { CalendarClock, CalendarRange, CalendarX, Trash2 } from 'lucide-react';
import { useMemo } from 'react';

const ONLY = ['batches', 'buckets', 'checks', 'wastage', 'filters'];
const WITHIN = [
    { value: '0', label: 'Out of date' },
    { value: '7', label: 'Within 7 days' },
    { value: '14', label: 'Within 14 days' },
    { value: '30', label: 'Within 30 days' },
    { value: '60', label: 'Within 60 days' },
];
const CHECK: Record<string, { label: string; tone: StatusTone }> = {
    ok: { label: 'Checked, fine', tone: 'success' },
    reduced: { label: 'Reduced', tone: 'warning' },
    wasted: { label: 'Wasted', tone: 'danger' },
};

function DaysLeft({ row }: { row: BatchRow }) {
    if (row.daysLeft === null) {
        return null;
    }
    if (row.expired) {
        return <StatusPill tone="danger">{`${number(Math.abs(row.daysLeft))} ${Math.abs(row.daysLeft) === 1 ? 'day' : 'days'} over`}</StatusPill>;
    }
    if (row.daysLeft === 0) {
        return <StatusPill tone="danger">Today</StatusPill>;
    }

    return (
        <StatusPill
            tone={row.daysLeft <= 3 ? 'warning' : 'neutral'}
        >{`${number(row.daysLeft)} ${row.daysLeft === 1 ? 'day' : 'days'} left`}</StatusPill>
    );
}

/** Dates and wastage (module 5.1): batches out of date or near it, the tills' date checks and what was written off. */
export default function StockExpiry({ batches, buckets, checks, wastage, filters, options }: ExpiryProps) {
    const { update, loading } = useTableQuery({ only: ONLY });
    const change = (params: TableParams) => {
        const { search, ...rest } = params;
        void search;
        update(rest);
    };
    const within = (days: number) => route('app.stock.expiry', withParams({ within: days }));

    const columns = useMemo<ColumnDef<BatchRow>[]>(
        () => [
            {
                id: 'product',
                header: 'Product',
                meta: { mobile: 'title' },
                cell: ({ row }) => (
                    <div className="grid max-w-64 leading-5">
                        <Link href={route('app.stock.products.show', row.original.productId)} className="truncate font-medium hover:underline">
                            {row.original.name}
                        </Link>
                        <span className="text-muted-foreground truncate text-xs">
                            {[row.original.shop, row.original.batch ? `Batch ${row.original.batch}` : null].filter(Boolean).join(' · ')}
                        </span>
                    </div>
                ),
            },
            {
                id: 'expiry',
                header: 'Best before',
                meta: { mobile: 'field' },
                cell: ({ row }) => (
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="whitespace-nowrap">{formatDay(row.original.expiry)}</span>
                        <DaysLeft row={row.original} />
                    </div>
                ),
            },
            {
                id: 'qty',
                header: 'Left',
                meta: { align: 'right', mobile: 'aside' },
                cell: ({ row }) => <Qty value={row.original.qty} className="font-medium" />,
            },
            {
                id: 'value',
                header: 'Value at cost',
                meta: { align: 'right', mobile: 'field' },
                cell: ({ row }) => <span className="tabular-nums">{money(row.original.value)}</span>,
            },
        ],
        [],
    );

    return (
        <AppLayout>
            <Head title="Dates and wastage" />

            <PageHeader
                title="Dates and wastage"
                description="Batches past or near their best-before date, the date checks done on your tills, and the stock written off."
                tabs={<StockTabs active="expiry" shop={filters.shopLocked ? null : filters.shop} />}
            />

            <StatGrid columns={4}>
                <StatCard
                    label="Out of date"
                    value={number(buckets.expired.count)}
                    hint={`${qty(buckets.expired.qty)} units · ${money(buckets.expired.value)}`}
                    icon={CalendarX}
                    tone="danger"
                    href={within(0)}
                />
                <StatCard
                    label="Due in 7 days"
                    value={number(buckets.week.count)}
                    hint={`${qty(buckets.week.qty)} units · ${money(buckets.week.value)}`}
                    icon={CalendarClock}
                    tone="warning"
                    href={within(7)}
                />
                <StatCard
                    label="Due in 30 days"
                    value={number(buckets.month.count)}
                    hint={`${qty(buckets.month.qty)} units · ${money(buckets.month.value)}`}
                    icon={CalendarRange}
                    tone="neutral"
                    href={within(30)}
                />
                <StatCard
                    label="Written off"
                    value={money(wastage.value)}
                    hint={`${qty(wastage.qty)} units, ${formatDay(filters.from)} – ${formatDay(filters.to)}`}
                    icon={Trash2}
                    tone="danger"
                />
            </StatGrid>

            <DataTable
                columns={columns}
                data={batches.data}
                meta={batches.meta}
                onChange={change}
                searchable={false}
                filters={
                    <div className="flex w-full flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                        <StockFilters filters={filters} options={options} update={update} show={{ dates: true }} />
                        <FilterSelect
                            value={filters.within === 14 ? null : String(filters.within)}
                            onChange={(value) => update({ within: value ?? '14', page: undefined })}
                            all="Within 14 days"
                            options={WITHIN.filter((w) => w.value !== '14')}
                            label="Best before"
                        />
                    </div>
                }
                getRowId={(row) => row.id}
                loading={loading}
                empty={
                    <EmptyState
                        icon={CalendarClock}
                        title={filters.within === 0 ? 'Nothing out of date' : 'Nothing due'}
                        body="No batch with stock left has a best-before date in this window. Dated batches come from deliveries booked in on the tills."
                    />
                }
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 lg:items-start">
                <SectionCard
                    title="Written off"
                    description={`Wastage, damage, out of date and theft, ${formatDay(filters.from)} – ${formatDay(filters.to)}.`}
                >
                    {wastage.rows.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Nothing written off in these days.</p>
                    ) : (
                        <ul className="divide-y">
                            {wastage.rows.map((w) => (
                                <li key={w.type} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                                    <span>
                                        <span className="font-medium">{w.label}</span>
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {number(w.count)} {w.count === 1 ? 'time' : 'times'}, {qty(w.qty)} units
                                        </span>
                                    </span>
                                    <span className="font-medium tabular-nums">{money(w.value)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                    <Link
                        href={route('app.stock.movements', withParams({ type: 'wastage', within: undefined }))}
                        className="text-primary mt-3 inline-block text-sm hover:underline"
                    >
                        See every write-off
                    </Link>
                </SectionCard>
                <SectionCard title="Date checks" description="Checks done on the tills in these days, newest first.">
                    {checks.length === 0 ? (
                        <p className="text-muted-foreground text-sm">No date checks in these days.</p>
                    ) : (
                        <ul className="divide-y">
                            {checks.map((c) => (
                                <li key={c.id} className="flex items-start justify-between gap-3 py-2.5 text-sm">
                                    <span className="grid leading-5">
                                        <Link href={route('app.stock.products.show', c.productId)} className="font-medium hover:underline">
                                            {c.name}
                                        </Link>
                                        <span className="text-muted-foreground text-xs">
                                            {[c.shop, formatDateTime(c.checkedAt), c.checkedBy, c.note].filter(Boolean).join(' · ')}
                                        </span>
                                    </span>
                                    <StatusPill tone={CHECK[c.action ?? '']?.tone ?? 'neutral'}>
                                        {CHECK[c.action ?? '']?.label ?? 'Checked'}
                                        {c.action === 'reduced' && c.markdownPercent ? ` ${Number(c.markdownPercent)}%` : ''}
                                    </StatusPill>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>
        </AppLayout>
    );
}
