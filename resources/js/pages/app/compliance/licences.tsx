import { ComplianceFilters, CompliancePageLayout } from '@/components/app/compliance/compliance-page';
import { dash, EXPIRY_FILTERS, ExpiryBadge, formatDay, Stack } from '@/components/app/compliance/format';
import { type LicenceRow, type LicencesProps } from '@/components/app/compliance/types';
import { FilterSelect } from '@/components/app/setup/fields';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { type ColumnDef } from '@tanstack/react-table';
import { BadgeCheck, CalendarClock, CalendarX } from 'lucide-react';

const columns: ColumnDef<LicenceRow>[] = [
    {
        id: 'licence_type',
        header: 'Licence',
        enableSorting: true,
        meta: { mobile: 'title' },
        cell: ({ row }) => <Stack main={row.original.type ?? 'Licence'} sub={row.original.number} />,
    },
    { id: 'holder', header: 'Holder', cell: ({ row }) => row.original.holder ?? dash },
    { id: 'shop', header: 'Shop', meta: { mobile: 'hidden' }, cell: ({ row }) => row.original.shop ?? dash },
    {
        id: 'issued',
        header: 'Issued',
        meta: { mobile: 'hidden' },
        cell: ({ row }) => <span className="tabular-nums">{row.original.issuedOn ? formatDay(row.original.issuedOn) : '—'}</span>,
    },
    {
        id: 'expires_on',
        header: 'Expires',
        enableSorting: true,
        cell: ({ row }) => <span className="tabular-nums">{row.original.expiresOn ? formatDay(row.original.expiresOn) : 'Never'}</span>,
    },
    {
        id: 'status',
        header: 'Status',
        meta: { mobile: 'aside' },
        cell: ({ row }) => <ExpiryBadge status={row.original.status} daysLeft={row.original.daysLeft} />,
    },
];

/** Licences held (module 5.7): premises, personal, alcohol, tobacco, lottery… with expiry reminders. Read only. */
export default function ComplianceLicences({ licences, summary, types, soonDays, filters, options }: LicencesProps) {
    const { update, loading } = useTableQuery();

    return (
        <CompliancePageLayout
            tab="licences"
            filters={filters}
            title="Licences"
            description={`The licences each shop recorded on its till. Expiring soon = within ${soonDays} days, time enough to renew.`}
        >
            <StatGrid columns={3}>
                <StatCard label="Licences held" value={summary.total.toLocaleString('en-GB')} icon={BadgeCheck} tone="neutral" />
                <StatCard
                    label="Expiring soon"
                    value={summary.expiring.toLocaleString('en-GB')}
                    hint={`Within ${soonDays} days`}
                    icon={CalendarClock}
                    tone={summary.expiring > 0 ? 'warning' : 'success'}
                />
                <StatCard
                    label="Expired"
                    value={summary.expired.toLocaleString('en-GB')}
                    hint="Renew before trading these lines"
                    icon={CalendarX}
                    tone={summary.expired > 0 ? 'danger' : 'success'}
                />
            </StatGrid>

            <DataTable
                columns={columns}
                data={licences.data}
                meta={licences.meta}
                onChange={update}
                loading={loading}
                searchPlaceholder="Search type, number or holder"
                filters={
                    <ComplianceFilters filters={filters} options={options} update={update} dates={false} staff={false}>
                        {types.length > 1 && (
                            <FilterSelect
                                value={filters.type}
                                onChange={(type) => update({ type, page: undefined })}
                                all="Every type"
                                options={types}
                                label="Filter by licence type"
                            />
                        )}
                        <FilterSelect
                            value={filters.status}
                            onChange={(status) => update({ status, page: undefined })}
                            all="Any status"
                            options={EXPIRY_FILTERS}
                            label="Filter by status"
                        />
                    </ComplianceFilters>
                }
                getRowId={(row) => row.id}
                empty={
                    <EmptyState
                        icon={BadgeCheck}
                        title="No licences recorded"
                        body="Licences appear here once a shop adds them on the till and syncs."
                    />
                }
            />
        </CompliancePageLayout>
    );
}
