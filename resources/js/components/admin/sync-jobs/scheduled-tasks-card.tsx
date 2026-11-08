import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { formatNumber, timeZoneLabel } from '@/lib/country';
import { CalendarClock } from 'lucide-react';
import { duration, RunStatePill, When } from './format';
import { ListTable, type ListColumn } from './list-table';
import { type ScheduledTask } from './types';

const columns: ListColumn<ScheduledTask>[] = [
    {
        key: 'task',
        header: 'Task',
        className: 'max-w-80',
        cell: (task) => (
            <div className="min-w-0">
                <div className="text-foreground text-sm font-medium">{task.label}</div>
                {task.label !== task.task && <div className="text-muted-foreground mt-0.5 font-mono text-[11px] break-all">{task.task}</div>}
                <div className="text-muted-foreground mt-0.5 text-xs" title={task.expression}>
                    {task.frequency}
                    {task.timezone !== 'UTC' ? ` (${task.timezone})` : ' (UTC)'}
                </div>
            </div>
        ),
    },
    {
        key: 'result',
        header: 'Result',
        mobile: 'aside',
        cell: (task) => (
            <div className="flex flex-wrap items-center gap-1.5">
                <RunStatePill state={task.lastRun?.state ?? null} />
                {task.late && <StatusPill tone="warning">Late</StatusPill>}
            </div>
        ),
    },
    {
        key: 'last',
        header: 'Last run',
        cell: (task) => (
            <div>
                <When at={task.lastRun?.startedAt ?? null} fallback="Not recorded yet" />
                {task.lastRun?.error && <div className="text-destructive mt-0.5 max-w-72 text-xs break-words">{task.lastRun.error}</div>}
            </div>
        ),
    },
    { key: 'duration', header: 'Duration', align: 'right', cell: (task) => duration(task.lastRun?.durationMs ?? null) },
    {
        key: 'week',
        header: 'Last 7 days',
        align: 'right',
        cell: (task) =>
            task.runs7d === 0 ? (
                <span className="text-muted-foreground">—</span>
            ) : (
                <span className={task.failed7d > 0 ? 'text-destructive font-medium' : undefined}>
                    {task.failed7d > 0 ? `${formatNumber(task.failed7d)} failed of ` : ''}
                    {formatNumber(task.runs7d)} {task.runs7d === 1 ? 'run' : 'runs'}
                </span>
            ),
    },
    { key: 'next', header: 'Next due', cell: (task) => <When at={task.nextDueAt} fallback="Unknown" future /> },
];

/** Every task in routes/console.php with its last recorded run and when it is next due. */
export function ScheduledTasksCard({ tasks }: { tasks: ScheduledTask[] }) {
    const failing = tasks.filter((task) => task.lastRun?.state === 'failed').length;
    const late = tasks.filter((task) => task.late).length;
    const summary =
        failing > 0 ? (
            <StatusPill tone="danger">{failing === 1 ? '1 task failed its last run' : `${failing} tasks failed their last run`}</StatusPill>
        ) : late > 0 ? (
            <StatusPill tone="warning">{late === 1 ? '1 task is late' : `${late} tasks are late`}</StatusPill>
        ) : tasks.some((task) => task.lastRun) ? (
            <StatusPill tone="success">Last runs succeeded</StatusPill>
        ) : null;

    return (
        <SectionCard
            id="scheduled-tasks"
            className="scroll-mt-24"
            flush
            title="Scheduled tasks"
            description={`${formatNumber(tasks.length)} ${tasks.length === 1 ? 'task' : 'tasks'} run by the scheduler. Every run is recorded and kept for 30 days. Times are ${timeZoneLabel()} time.`}
            actions={summary}
        >
            {tasks.length === 0 ? (
                <EmptyState
                    icon={CalendarClock}
                    size="sm"
                    title="No scheduled tasks"
                    body="Tasks added to routes/console.php show here with their runs."
                />
            ) : (
                <ListTable label="Scheduled tasks" columns={columns} rows={tasks} getKey={(task) => task.key} />
            )}
        </SectionCard>
    );
}
