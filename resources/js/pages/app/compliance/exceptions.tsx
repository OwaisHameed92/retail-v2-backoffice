import { Money } from '@/components/app/cash/format';
import { ComplianceFilters, CompliancePageLayout } from '@/components/app/compliance/compliance-page';
import { dash, formatDateTime, Stack } from '@/components/app/compliance/format';
import { type ExceptionLogRow, type ExceptionsProps } from '@/components/app/compliance/types';
import { FilterSelect } from '@/components/app/setup/fields';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusPill } from '@/components/shared/status-badge';
import { money } from '@/components/shared/trading/format';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { type ColumnDef } from '@tanstack/react-table';
import { Ban, Eye, ListX, PoundSterling } from 'lucide-react';

const columns: ColumnDef<ExceptionLogRow>[] = [
    {
        id: 'at',
        header: 'When',
        enableSorting: true,
        meta: { mobile: 'title' },
        cell: ({ row }) => <span className="tabular-nums">{formatDateTime(row.original.at)}</span>,
    },
    { id: 'type', header: 'Exception', cell: ({ row }) => <StatusPill tone="neutral">{row.original.type}</StatusPill> },
    { id: 'staff', header: 'Staff', cell: ({ row }) => row.original.staff ?? dash },
    {
        id: 'where',
        header: 'Shop and till',
        meta: { mobile: 'hidden' },
        cell: ({ row }) => <Stack main={row.original.shop} sub={row.original.till} />,
    },
    {
        id: 'detail',
        header: 'Detail',
        meta: { mobile: 'hidden' },
        cell: ({ row }) => (row.original.detail ? <span className="line-clamp-2 max-w-72 text-sm">{row.original.detail}</span> : dash),
    },
    { id: 'amount', header: 'Amount', meta: { align: 'right', mobile: 'aside' }, cell: ({ row }) => <Money value={row.original.amount} /> },
];

/** Exceptions per staff member (module 5.7): no sales, voided lines and other till exceptions. The loss-prevention view. */
export default function ComplianceExceptions({ staff, types, summary, log, filters, options }: ExceptionsProps) {
    const { update, loading } = useTableQuery();
    const typeOptions = [...types.map((t) => ({ value: t.value, label: `${t.label} (${t.count})` })), { value: 'LineVoided', label: 'Voided lines' }];

    return (
        <CompliancePageLayout
            tab="exceptions"
            filters={filters}
            title="Exceptions"
            description="Drawer opened with no sale, lines voided from a basket and other till exceptions, per staff member. Voided lines are logged only when the till asks a reason for voids."
        >
            <ComplianceFilters filters={filters} options={options} update={update}>
                <FilterSelect
                    value={filters.type}
                    onChange={(type) => update({ type, page: undefined })}
                    all="Every exception"
                    options={typeOptions}
                    label="Filter the log"
                />
            </ComplianceFilters>

            <StatGrid>
                <StatCard
                    label="No sales"
                    value={summary.noSales.toLocaleString('en-GB')}
                    hint="Drawer opened without a sale"
                    icon={Eye}
                    tone="neutral"
                />
                <StatCard
                    label="Voided lines"
                    value={summary.voidedLines.toLocaleString('en-GB')}
                    hint="Removed from a basket"
                    icon={ListX}
                    tone="neutral"
                />
                <StatCard label="Other exceptions" value={summary.other.toLocaleString('en-GB')} icon={Ban} tone="neutral" />
                <StatCard label="Exception value" value={money(summary.amount)} hint="Sum of logged amounts" icon={PoundSterling} tone="neutral" />
            </StatGrid>

            <SectionCard
                title="By staff member"
                description="Most exceptions first; pick a name to see their log. A high count is worth a look, not proof of wrongdoing."
                flush
            >
                {staff.length === 0 ? (
                    <EmptyState icon={Eye} title="No exceptions in these dates" body="Exceptions appear here once the tills log them and sync." />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-5">Staff member</TableHead>
                                <TableHead className="text-right">No sales</TableHead>
                                <TableHead className="text-right">Voided lines</TableHead>
                                <TableHead className="text-right">Other</TableHead>
                                <TableHead className="text-right">Total</TableHead>
                                <TableHead className="pr-5 text-right">Value</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {staff.map((s) => (
                                <TableRow key={s.key ?? s.staff}>
                                    <TableCell className="pl-5 font-medium">
                                        {s.key && s.key !== filters.staff ? (
                                            <button
                                                type="button"
                                                className="hover:text-primary text-left hover:underline"
                                                onClick={() => update({ staff: s.key ?? undefined, page: undefined })}
                                            >
                                                {s.staff}
                                            </button>
                                        ) : (
                                            s.staff
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">{s.noSales}</TableCell>
                                    <TableCell className="text-right tabular-nums">{s.voidedLines}</TableCell>
                                    <TableCell className="text-right tabular-nums">{s.other}</TableCell>
                                    <TableCell className="text-right font-medium tabular-nums">{s.total}</TableCell>
                                    <TableCell className="pr-5 text-right">
                                        <Money value={s.amount} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </SectionCard>

            <SectionCard title={log.kind === 'voids' ? 'Voided lines' : 'Exceptions log'} description="Newest first." flush>
                <DataTable
                    columns={columns}
                    data={log.data}
                    meta={log.meta}
                    onChange={update}
                    loading={loading}
                    searchable={false}
                    getRowId={(row) => row.id}
                    empty={<EmptyState icon={Eye} title="Nothing logged in these dates" body="Try a longer date range or another shop." />}
                />
            </SectionCard>
        </CompliancePageLayout>
    );
}
