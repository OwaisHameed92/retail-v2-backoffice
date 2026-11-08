import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { DataTable, useTableQuery, type Paginated } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { RowActions } from '@/components/shared/row-actions';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatNumber } from '@/lib/country';
import { Link } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { CircleCheck, Eye, RotateCcw, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { sendFailedJobs } from './failed-job-actions';
import { FailedJobDialog } from './failed-job-dialog';
import { When } from './format';
import { type FailedJobRow } from './types';

const ONLY = ['failedJobs', 'failedFilters'];

interface FailedJobsCardProps {
    jobs: Paginated<FailedJobRow>;
    filters: { queue: string | null; queues: string[] };
}

function QueueFilter({ filters }: { filters: FailedJobsCardProps['filters'] }) {
    const { update } = useTableQuery({ only: ONLY });
    if (filters.queues.length < 2 && !filters.queue) {
        return null;
    }

    return (
        <Select value={filters.queue ?? 'all'} onValueChange={(value) => update({ queue: value === 'all' ? undefined : value, page: 1 })}>
            <SelectTrigger className="h-9 w-full sm:w-44" aria-label="Filter by queue">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="all">All queues</SelectItem>
                {filters.queues.map((queue) => (
                    <SelectItem key={queue} value={queue}>
                        {queue}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/** Failed queue jobs, newest first: details, Retry (one or every row shown) and Delete. */
export function FailedJobsCard({ jobs, filters }: FailedJobsCardProps) {
    const [viewing, setViewing] = useState<FailedJobRow | null>(null);
    const [deleting, setDeleting] = useState<FailedJobRow | null>(null);
    const [retryAll, setRetryAll] = useState(false);
    const shown = jobs.data;

    const columns = useMemo<ColumnDef<FailedJobRow>[]>(
        () => [
            {
                id: 'job',
                header: 'Job',
                cell: ({ row }) => (
                    <div className="min-w-0">
                        <div className="text-foreground truncate font-medium" title={row.original.jobClass}>
                            {row.original.job}
                        </div>
                        <div className="text-muted-foreground mt-0.5 max-w-md truncate text-xs" title={row.original.error}>
                            {row.original.error || 'No error text'}
                        </div>
                    </div>
                ),
            },
            {
                id: 'business',
                header: 'Business',
                cell: ({ row }) =>
                    row.original.business ? (
                        <Link
                            href={route('admin.tenants.show', row.original.business.id)}
                            onClick={(event) => event.stopPropagation()}
                            className="text-primary font-medium hover:underline"
                        >
                            {row.original.business.name}
                        </Link>
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    ),
            },
            {
                id: 'queue',
                header: 'Queue',
                enableSorting: true,
                cell: ({ row }) => <span className="font-mono text-[13px]">{row.original.queue}</span>,
            },
            {
                id: 'failed_at',
                header: 'Failed',
                enableSorting: true,
                meta: { mobile: 'aside' },
                cell: ({ row }) => <When at={row.original.failedAt} />,
            },
            {
                id: 'actions',
                header: '',
                meta: { align: 'right', label: 'Actions' },
                cell: ({ row }) => (
                    <RowActions
                        label={`Actions for ${row.original.job}`}
                        actions={[
                            { label: 'View error', icon: Eye, onSelect: () => setViewing(row.original) },
                            { label: 'Retry', icon: RotateCcw, onSelect: () => void sendFailedJobs('retry', [row.original.uuid]) },
                            { label: 'Delete', icon: Trash2, destructive: true, onSelect: () => setDeleting(row.original) },
                        ]}
                    />
                ),
            },
        ],
        [],
    );

    const noneAtAll = jobs.meta.total === 0 && !jobs.meta.search && !filters.queue;

    return (
        <section id="failed-jobs" aria-labelledby="failed-jobs-title" className="scroll-mt-24 space-y-3">
            <div className="flex flex-col gap-1">
                <h2 id="failed-jobs-title" className="text-foreground text-[15px] leading-6 font-semibold tracking-tight">
                    Failed jobs
                </h2>
                <p className="text-muted-foreground text-sm">
                    {noneAtAll
                        ? 'Background jobs that ran out of tries show here, with their error.'
                        : `${formatNumber(jobs.meta.total)} ${jobs.meta.total === 1 ? 'job' : 'jobs'} ran out of tries. Retry sends a job back to its queue; delete removes it for good.`}
                </p>
            </div>

            <DataTable
                columns={columns}
                data={shown}
                meta={jobs.meta}
                only={ONLY}
                searchPlaceholder="Search job, error or queue"
                filters={<QueueFilter filters={filters} />}
                toolbarActions={
                    shown.length > 1 ? (
                        <Button variant="outline" className="h-9" onClick={() => setRetryAll(true)}>
                            <RotateCcw />
                            Retry all shown ({formatNumber(shown.length)})
                        </Button>
                    ) : undefined
                }
                getRowId={(row) => row.uuid}
                onRowClick={(row) => setViewing(row)}
                empty={
                    noneAtAll ? (
                        <EmptyState
                            icon={CircleCheck}
                            tone="success"
                            title="No failed jobs"
                            body="Every background job finished. Jobs that fail after all their tries appear here."
                        />
                    ) : undefined
                }
            />

            <FailedJobDialog
                job={viewing}
                onOpenChange={(open) => !open && setViewing(null)}
                onRetry={(job) => sendFailedJobs('retry', [job.uuid])}
                onDelete={(job) => {
                    setViewing(null);
                    setDeleting(job);
                }}
            />

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Delete ${deleting?.job ?? 'this job'}?`}
                description="It is removed from the failed list and will not run again. This cannot be undone."
                confirmLabel="Delete job"
                destructive
                onConfirm={async () => {
                    if (deleting) {
                        await sendFailedJobs('delete', [deleting.uuid]);
                    }
                    setDeleting(null);
                }}
            />

            <ConfirmDialog
                open={retryAll}
                onOpenChange={setRetryAll}
                title={`Retry ${formatNumber(shown.length)} failed jobs?`}
                description="Every job shown on this page goes back to its queue and runs again. Jobs that fail again come back to this list."
                confirmLabel="Retry jobs"
                onConfirm={async () => {
                    await sendFailedJobs(
                        'retry',
                        shown.map((job) => job.uuid),
                    );
                    setRetryAll(false);
                }}
            />
        </section>
    );
}
