import { ComplianceFilters, CompliancePageLayout } from '@/components/app/compliance/compliance-page';
import { BreakdownTable, dash, formatDateTime, Rate, Stack } from '@/components/app/compliance/format';
import { type AgeChecksProps, type RefusalRow } from '@/components/app/compliance/types';
import { FilterSelect } from '@/components/app/setup/fields';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageTabs } from '@/components/shared/page-tabs';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { type ColumnDef } from '@tanstack/react-table';
import { IdCard, Percent, ShieldCheck, ShieldX } from 'lucide-react';
import { useState } from 'react';

const columns: ColumnDef<RefusalRow>[] = [
    {
        id: 'at',
        header: 'When',
        enableSorting: true,
        meta: { mobile: 'title' },
        cell: ({ row }) => <span className="tabular-nums">{formatDateTime(row.original.at)}</span>,
    },
    { id: 'product', header: 'Product', cell: ({ row }) => <Stack main={row.original.product} sub={row.original.rule} /> },
    { id: 'staff', header: 'Staff', cell: ({ row }) => row.original.staff ?? dash },
    {
        id: 'where',
        header: 'Shop and till',
        meta: { mobile: 'hidden' },
        cell: ({ row }) => <Stack main={row.original.shop} sub={row.original.till} />,
    },
    {
        id: 'note',
        header: 'Note',
        meta: { mobile: 'hidden' },
        cell: ({ row }) => (row.original.note ? <span className="line-clamp-2 max-w-72 text-sm">{row.original.note}</span> : dash),
    },
];

type Dimension = 'shop' | 'staff' | 'rule' | 'product';

/** Age checks and refusals (module 5.7): figures, breakdowns and the refusals log. Read only: the tills' rows. */
export default function ComplianceAgeChecks({ summary, byShop, byStaff, byRule, byProduct, refusalLog, rules, filters, options }: AgeChecksProps) {
    const { update, loading } = useTableQuery();
    const [dimension, setDimension] = useState<Dimension>('shop');
    const breakdowns: Record<Dimension, { rows: typeof byShop; label: string }> = {
        shop: { rows: byShop, label: 'Shop' },
        staff: { rows: byStaff, label: 'Staff member' },
        rule: { rows: byRule, label: 'Age rule' },
        product: { rows: byProduct, label: 'Product (most refused)' },
    };

    return (
        <CompliancePageLayout
            tab="age"
            filters={filters}
            title="Age checks"
            description="Age-restricted sales that passed a check, and the sales the tills refused. Refusal rate = refusals ÷ (checks + refusals)."
        >
            <ComplianceFilters filters={filters} options={options} update={update}>
                <FilterSelect
                    value={filters.rule}
                    onChange={(rule) => update({ rule, page: undefined })}
                    all="Every age rule"
                    options={rules}
                    label="Filter by age rule"
                />
            </ComplianceFilters>

            <StatGrid columns={3}>
                <StatCard
                    label="Age checks passed"
                    value={summary.checks.toLocaleString('en-GB')}
                    hint="Sales with an age-restricted item"
                    icon={ShieldCheck}
                    tone="success"
                />
                <StatCard
                    label="Refusals"
                    value={summary.refusals.toLocaleString('en-GB')}
                    hint="Sales the till refused"
                    icon={ShieldX}
                    tone="neutral"
                />
                <StatCard
                    label="Refusal rate"
                    value={<Rate value={summary.rate} />}
                    hint="A high rate means staff are challenging"
                    icon={Percent}
                    tone="primary"
                />
            </StatGrid>

            <SectionCard
                title="Breakdown"
                flush
                actions={
                    <PageTabs
                        label="Break down by"
                        value={dimension}
                        onChange={(v) => setDimension(v as Dimension)}
                        tabs={[
                            { label: 'Shop', value: 'shop' },
                            { label: 'Staff', value: 'staff' },
                            { label: 'Age rule', value: 'rule' },
                            { label: 'Product', value: 'product' },
                        ]}
                    />
                }
            >
                <div id={`tab-panel-${dimension}`}>
                    <BreakdownTable
                        rows={breakdowns[dimension].rows}
                        label={breakdowns[dimension].label}
                        empty="No age-restricted sales or refusals in these dates."
                    />
                </div>
            </SectionCard>

            <SectionCard title="Refusals log" description="Each refusal the tills recorded, newest first." flush>
                <DataTable
                    columns={columns}
                    data={refusalLog.data}
                    meta={refusalLog.meta}
                    onChange={update}
                    loading={loading}
                    searchable={false}
                    getRowId={(row) => row.id}
                    empty={
                        <EmptyState
                            icon={IdCard}
                            title="No refusals in these dates"
                            body="A refusal appears here once a till records one and syncs."
                        />
                    }
                />
            </SectionCard>
        </CompliancePageLayout>
    );
}
