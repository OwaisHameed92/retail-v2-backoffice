import { ComplianceFilters, CompliancePageLayout } from '@/components/app/compliance/compliance-page';
import { dash, EXPIRY_FILTERS, ExpiryBadge, formatDay, Stack } from '@/components/app/compliance/format';
import { type TrainingProps, type TrainingRow } from '@/components/app/compliance/types';
import { FilterSelect } from '@/components/app/setup/fields';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { formatNumber } from '@/lib/country';
import { type ColumnDef } from '@tanstack/react-table';
import { CalendarClock, CalendarX, GraduationCap, Users } from 'lucide-react';

const columns: ColumnDef<TrainingRow>[] = [
    {
        id: 'staff',
        header: 'Staff member',
        meta: { mobile: 'title' },
        cell: ({ row }) => <Stack main={row.original.staff ?? 'Unknown staff'} sub={row.original.shop} />,
    },
    { id: 'topic', header: 'Trained on', enableSorting: true, cell: ({ row }) => <span className="font-medium">{row.original.topic ?? '—'}</span> },
    {
        id: 'trained_on',
        header: 'Date',
        enableSorting: true,
        cell: ({ row }) => <span className="tabular-nums">{row.original.trainedOn ? formatDay(row.original.trainedOn) : '—'}</span>,
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
    { id: 'trainer', header: 'Trainer', meta: { mobile: 'hidden' }, cell: ({ row }) => row.original.trainer ?? dash },
];

/** Staff training records (module 5.7): who is trained on what, and what runs out soon. Read only: the shops keep them. */
export default function ComplianceTraining({ records, summary, topics, soonDays, filters, options }: TrainingProps) {
    const { update, loading } = useTableQuery();

    return (
        <CompliancePageLayout
            tab="training"
            filters={filters}
            title="Training"
            description={`Training the shops recorded for their staff (age checks, licensing, food safety…). Expiring soon = within ${soonDays} days.`}
        >
            <StatGrid>
                <StatCard label="Training records" value={formatNumber(summary.total)} icon={GraduationCap} tone="neutral" />
                <StatCard label="Staff trained" value={formatNumber(summary.staff)} icon={Users} tone="neutral" />
                <StatCard
                    label="Expiring soon"
                    value={formatNumber(summary.expiring)}
                    hint={`Within ${soonDays} days`}
                    icon={CalendarClock}
                    tone={summary.expiring > 0 ? 'warning' : 'success'}
                />
                <StatCard
                    label="Expired"
                    value={formatNumber(summary.expired)}
                    hint="Retrain these staff"
                    icon={CalendarX}
                    tone={summary.expired > 0 ? 'danger' : 'success'}
                />
            </StatGrid>

            <DataTable
                columns={columns}
                data={records.data}
                meta={records.meta}
                onChange={update}
                loading={loading}
                searchPlaceholder="Search topic or trainer"
                filters={
                    <ComplianceFilters filters={filters} options={options} update={update} dates={false}>
                        {topics.length > 1 && (
                            <FilterSelect
                                value={filters.type}
                                onChange={(type) => update({ type, page: undefined })}
                                all="Every topic"
                                options={topics}
                                label="Filter by topic"
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
                        icon={GraduationCap}
                        title="No training records"
                        body="Training appears here once a shop records it on the till and syncs."
                    />
                }
            />
        </CompliancePageLayout>
    );
}
