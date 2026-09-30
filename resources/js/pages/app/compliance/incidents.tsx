import { ComplianceFilters, CompliancePageLayout } from '@/components/app/compliance/compliance-page';
import { dash, formatDateTime, Stack } from '@/components/app/compliance/format';
import { type IncidentRow, type IncidentsProps } from '@/components/app/compliance/types';
import { FilterSelect } from '@/components/app/setup/fields';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusPill } from '@/components/shared/status-badge';
import { router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { FileWarning, Siren, Tags } from 'lucide-react';

const columns: ColumnDef<IncidentRow>[] = [
    {
        id: 'occurred_at',
        header: 'When',
        enableSorting: true,
        meta: { mobile: 'title' },
        cell: ({ row }) => <span className="tabular-nums">{formatDateTime(row.original.occurredAt)}</span>,
    },
    {
        id: 'category',
        header: 'Category',
        enableSorting: true,
        meta: { mobile: 'aside' },
        cell: ({ row }) => <StatusPill tone="neutral">{row.original.category ?? 'Uncategorised'}</StatusPill>,
    },
    {
        id: 'description',
        header: 'What happened',
        cell: ({ row }) => (row.original.description ? <span className="line-clamp-2 max-w-96 text-sm">{row.original.description}</span> : dash),
    },
    {
        id: 'who',
        header: 'Shop',
        meta: { mobile: 'hidden' },
        cell: ({ row }) => <Stack main={row.original.shop} sub={row.original.reportedBy ? `By ${row.original.reportedBy}` : null} />,
    },
    {
        id: 'refs',
        header: 'References',
        meta: { mobile: 'hidden' },
        cell: ({ row }) =>
            row.original.police || row.original.insurer ? (
                <Stack
                    main={row.original.police ? `Police ${row.original.police}` : null}
                    sub={row.original.insurer ? `Insurer ${row.original.insurer}` : null}
                />
            ) : (
                dash
            ),
    },
];

/** Incident log (module 5.7): the incidents the shops recorded on their tills. Read only. */
export default function ComplianceIncidents({ incidents, categories, summary, filters, options }: IncidentsProps) {
    const { update, loading } = useTableQuery();
    const top = categories[0];

    return (
        <CompliancePageLayout
            tab="incidents"
            filters={filters}
            title="Incidents"
            description="Theft, abuse, accidents and other incidents the shops recorded on their tills, newest first."
        >
            <StatGrid columns={3}>
                <StatCard label="Incidents" value={summary.total.toLocaleString('en-GB')} hint="In these dates" icon={FileWarning} tone="neutral" />
                <StatCard
                    label="Reported to police"
                    value={summary.police.toLocaleString('en-GB')}
                    hint="With a police reference"
                    icon={Siren}
                    tone="neutral"
                />
                <StatCard
                    label="Most common"
                    value={top ? top.label : '—'}
                    hint={top ? `${top.count} incident${top.count === 1 ? '' : 's'}` : 'No incidents'}
                    icon={Tags}
                    tone="neutral"
                />
            </StatGrid>

            <DataTable
                columns={columns}
                data={incidents.data}
                meta={incidents.meta}
                onChange={update}
                loading={loading}
                searchPlaceholder="Search incidents or references"
                filters={
                    <ComplianceFilters filters={filters} options={options} update={update}>
                        {categories.length > 1 && (
                            <FilterSelect
                                value={filters.type}
                                onChange={(type) => update({ type, page: undefined })}
                                all="Every category"
                                options={categories.map((c) => ({ value: c.value, label: `${c.label} (${c.count})` }))}
                                label="Filter by category"
                            />
                        )}
                    </ComplianceFilters>
                }
                getRowId={(row) => row.id}
                onRowClick={(row) => router.visit(route('app.compliance.incidents.show', row.id))}
                empty={
                    <EmptyState
                        icon={FileWarning}
                        title="No incidents in these dates"
                        body="An incident appears here once a shop records it on the till and syncs."
                    />
                }
            />
        </CompliancePageLayout>
    );
}
