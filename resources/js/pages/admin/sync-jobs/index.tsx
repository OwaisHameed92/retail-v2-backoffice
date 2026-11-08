import { FailedJobsCard } from '@/components/admin/sync-jobs/failed-jobs-card';
import { HealthStrip } from '@/components/admin/sync-jobs/health-strip';
import { ScheduledTasksCard } from '@/components/admin/sync-jobs/scheduled-tasks-card';
import { SyncProblemsCard } from '@/components/admin/sync-jobs/sync-problems-card';
import { TillTrafficCard } from '@/components/admin/sync-jobs/till-traffic-card';
import { type SyncJobsPageProps } from '@/components/admin/sync-jobs/types';
import { PageHeader } from '@/components/shared/page-header';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { formatTime } from '@/lib/country';
import { Head, router } from '@inertiajs/react';
import { LoaderCircle, RefreshCw } from 'lucide-react';
import { useState } from 'react';

export default function SyncJobsIndex({ checkedAt, health, tasks, failedJobs, failedFilters, syncProblems, traffic }: SyncJobsPageProps) {
    const [refreshing, setRefreshing] = useState(false);
    const refresh = () =>
        router.reload({
            onStart: () => setRefreshing(true),
            onFinish: () => setRefreshing(false),
        });

    return (
        <AdminLayout>
            <Head title="Sync & jobs" />

            <PageHeader
                title="Sync & jobs"
                description={`Scheduler, queue, scheduled tasks, failed jobs and till sync, without logging in to the server. Checked at ${formatTime(checkedAt)}.`}
                actions={
                    <Button variant="outline" onClick={refresh} disabled={refreshing}>
                        {refreshing ? <LoaderCircle className="animate-spin" /> : <RefreshCw />}
                        Refresh
                    </Button>
                }
            />

            <HealthStrip items={health} />

            <ScheduledTasksCard tasks={tasks} />

            <FailedJobsCard jobs={failedJobs} filters={failedFilters} />

            <SyncProblemsCard problems={syncProblems} />

            <TillTrafficCard traffic={traffic} />
        </AdminLayout>
    );
}
