import { showToast } from '@/components/shared/toaster';
import { router } from '@inertiajs/react';

type Kind = 'retry' | 'delete';

/**
 * Retry (`queue:retry`) or delete (`queue:forget`) failed jobs by uuid. Resolves when the request settles; the
 * server's flash message shows as a toast, validation errors as an error toast.
 */
export function sendFailedJobs(kind: Kind, uuids: string[]): Promise<void> {
    return new Promise((resolve) => {
        const options = {
            preserveScroll: true,
            onError: (errors: Record<string, string>) => showToast(Object.values(errors)[0] ?? 'Something went wrong. Try again.', 'error'),
            onFinish: () => resolve(),
        };

        if (kind === 'retry') {
            router.post(route('admin.sync-jobs.failed-jobs.retry'), { uuids }, options);
        } else {
            router.delete(route('admin.sync-jobs.failed-jobs.destroy'), { ...options, data: { uuids } });
        }
    });
}
