import { ComplianceFilters, CompliancePageLayout } from '@/components/app/compliance/compliance-page';
import { dash, formatDateTime, formatDay, scheduleLabel, Stack } from '@/components/app/compliance/format';
import { type DiaryProps, type DiaryRecordRow, type MissedRow } from '@/components/app/compliance/types';
import { FilterSelect } from '@/components/app/setup/fields';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusBadge, StatusPill } from '@/components/shared/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatNumber } from '@/lib/country';
import { type ColumnDef } from '@tanstack/react-table';
import { CalendarCheck, CalendarX, ClipboardCheck, ClipboardX } from 'lucide-react';

const columns: ColumnDef<DiaryRecordRow>[] = [
    {
        id: 'recorded_at',
        header: 'When',
        enableSorting: true,
        meta: { mobile: 'title' },
        cell: ({ row }) => <span className="tabular-nums">{formatDateTime(row.original.recordedAt)}</span>,
    },
    { id: 'check', header: 'Check', cell: ({ row }) => <Stack main={row.original.check ?? 'Unknown check'} sub={row.original.shop} /> },
    { id: 'value', header: 'Reading', cell: ({ row }) => row.original.value ?? dash },
    {
        id: 'status',
        header: 'Result',
        meta: { mobile: 'aside' },
        cell: ({ row }) => <StatusBadge status={row.original.passed ? 'passed' : 'failed'} tones={{ passed: 'success', failed: 'danger' }} />,
    },
    { id: 'staff', header: 'By', meta: { mobile: 'hidden' }, cell: ({ row }) => row.original.staff ?? dash },
    {
        id: 'note',
        header: 'Note',
        meta: { mobile: 'hidden' },
        cell: ({ row }) => (row.original.note ? <span className="line-clamp-2 max-w-64 text-sm">{row.original.note}</span> : dash),
    },
];

function missedWhen(m: MissedRow): string {
    if (m.period.startsWith('week:')) return `Week of ${formatDay(m.period.slice(5))}`;
    if (m.period.startsWith('shift:')) return `Shift from ${formatDateTime(m.start)}`;

    return formatDay(m.period);
}

/** Diary checks (module 5.7): definitions with due / done / missed per schedule, the missed list and the records log. */
export default function ComplianceDiary({ definitions, missed, summary, records, filters, options }: DiaryProps) {
    const { update, loading } = useTableQuery();

    return (
        <CompliancePageLayout
            tab="diary"
            filters={filters}
            title="Diary checks"
            description="The checks each shop set on its till (fridge temperatures, fire exits…), how often they were done and which were missed."
        >
            <ComplianceFilters filters={filters} options={options} update={update}>
                {definitions.length > 1 && (
                    <FilterSelect
                        value={filters.type}
                        onChange={(type) => update({ type, page: undefined })}
                        all="Every check"
                        options={definitions.map((d) => ({ value: d.id, label: d.shop && !filters.shop ? `${d.name} · ${d.shop}` : d.name }))}
                        label="Filter records by check"
                    />
                )}
            </ComplianceFilters>

            <StatGrid>
                <StatCard label="Checks set up" value={formatNumber(summary.definitions)} icon={ClipboardCheck} tone="neutral" />
                <StatCard
                    label="Done on time"
                    value={`${formatNumber(summary.done)} of ${formatNumber(summary.due)}`}
                    icon={CalendarCheck}
                    tone="success"
                />
                <StatCard label="Missed" value={formatNumber(summary.missed)} icon={CalendarX} tone={summary.missed > 0 ? 'warning' : 'success'} />
                <StatCard
                    label="Failed readings"
                    value={formatNumber(summary.failed)}
                    icon={ClipboardX}
                    tone={summary.failed > 0 ? 'danger' : 'success'}
                />
            </StatGrid>

            <SectionCard
                title="Checks"
                description="Due = once per day, week or shift in these dates, from when the check was set up. Every day counts until opening hours arrive."
                flush
            >
                {definitions.length === 0 ? (
                    <EmptyState
                        icon={ClipboardCheck}
                        title="No diary checks set up"
                        body="Checks appear here once a shop sets them up on its till and syncs."
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-5">Check</TableHead>
                                <TableHead>How often</TableHead>
                                <TableHead className="text-right">Due</TableHead>
                                <TableHead className="text-right">Done</TableHead>
                                <TableHead className="text-right">Missed</TableHead>
                                <TableHead className="pr-5">Last done</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {definitions.map((d) => (
                                <TableRow key={d.id}>
                                    <TableCell className="pl-5">
                                        <Stack main={d.name} sub={[d.category, d.shop].filter(Boolean).join(' · ') || null} />
                                    </TableCell>
                                    <TableCell>
                                        {d.active ? scheduleLabel(d.schedule) : <StatusPill tone="neutral">Switched off</StatusPill>}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">{d.due}</TableCell>
                                    <TableCell className="text-right tabular-nums">{d.done}</TableCell>
                                    <TableCell className="text-right">
                                        {d.missed > 0 ? <StatusPill tone="warning">{d.missed}</StatusPill> : <span className="tabular-nums">0</span>}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground pr-5 text-sm tabular-nums">
                                        {d.lastDoneAt ? formatDateTime(d.lastDoneAt) : 'Never'}
                                        {d.dueNow && <span className="text-warning-foreground ml-2 text-xs font-medium">Due now</span>}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </SectionCard>

            {missed.length > 0 && (
                <SectionCard
                    title="Missed checks"
                    description="Each day, week or shift that ended without the check being recorded, newest first."
                    flush
                >
                    <ul className="divide-border divide-y">
                        {missed.map((m) => (
                            <li key={`${m.definitionId}-${m.start}`} className="flex items-center gap-3 px-5 py-3 text-sm">
                                <StatusPill tone="warning" className="w-16 justify-center">
                                    Missed
                                </StatusPill>
                                <span className="min-w-0 flex-1 truncate font-medium">
                                    {m.name}
                                    {m.shop && <span className="text-muted-foreground font-normal"> · {m.shop}</span>}
                                </span>
                                <span className="text-muted-foreground shrink-0 tabular-nums">{missedWhen(m)}</span>
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            )}

            <SectionCard title="Records" description="Every check the tills recorded in these dates." flush>
                <DataTable
                    columns={columns}
                    data={records.data}
                    meta={records.meta}
                    onChange={update}
                    loading={loading}
                    searchable={false}
                    getRowId={(row) => row.id}
                    empty={
                        <EmptyState
                            icon={ClipboardCheck}
                            title="No checks recorded in these dates"
                            body="A check appears here once a till records it and syncs."
                        />
                    }
                />
            </SectionCard>
        </CompliancePageLayout>
    );
}
