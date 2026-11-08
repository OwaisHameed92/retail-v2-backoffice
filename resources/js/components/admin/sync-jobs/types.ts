import { type Paginated } from '@/components/shared/data-table';

/** Matches App\Domain\Operations\Queries\OperationsHealth::compute(). */
export type HealthState = 'ok' | 'warning' | 'problem' | 'unknown';

export interface HealthItem {
    key: 'scheduler' | 'queue' | 'failed' | 'sync';
    label: string;
    state: HealthState;
    value: string;
    detail: string;
    at: string | null;
    atLabel: string | null;
}

/** Matches ScheduledTaskList::compute(). */
export type RunState = 'succeeded' | 'failed' | 'running' | 'unfinished';

export interface ScheduledTask {
    key: string;
    task: string;
    label: string;
    frequency: string;
    expression: string;
    timezone: string;
    nextDueAt: string | null;
    lastRun: { startedAt: string; finishedAt: string | null; durationMs: number | null; state: RunState; error: string | null } | null;
    late: boolean;
    runs7d: number;
    failed7d: number;
}

export interface BusinessRef {
    id: string;
    name: string;
}

/** Matches FailedJobList::row(). */
export interface FailedJobRow {
    uuid: string;
    job: string;
    jobClass: string;
    queue: string;
    connection: string;
    failedAt: string | null;
    error: string;
    attempts: number | null;
    business: BusinessRef | null;
}

/** Matches FailedJobDetail::for(). */
export interface FailedJobDetail extends FailedJobRow {
    maxTries: number | null;
    timeout: number | null;
    records: { type: string; id: string }[];
    exception: string;
    exceptionTruncated: boolean;
}

/** Matches SyncProblemList::compute() rows. */
export type ProblemKind = 'refused' | 'unknown' | 'conflict';

export interface SyncProblem {
    key: string;
    kind: ProblemKind;
    business: BusinessRef;
    shop: string | null;
    till: string | null;
    entity: string | null;
    count: number;
    lastSeenAt: string;
    detail: string | null;
}

/** Matches TillTrafficList::compute() rows. */
export interface TrafficRow {
    business: BusinessRef;
    shops: number;
    lastPushAt: string | null;
    lastPullAt: string | null;
    lastHelloAt: string | null;
    acceptedToday: number;
    rejectedToday: number;
    quiet: boolean;
}

export interface SyncJobsPageProps {
    checkedAt: string;
    health: HealthItem[];
    tasks: ScheduledTask[];
    failedJobs: Paginated<FailedJobRow>;
    failedFilters: { queue: string | null; queues: string[] };
    syncProblems: { rows: SyncProblem[]; total: number; windowDays: number };
    traffic: { recent: TrafficRow[]; quiet: TrafficRow[]; quietTotal: number; businesses: number; quietHours: number };
}
