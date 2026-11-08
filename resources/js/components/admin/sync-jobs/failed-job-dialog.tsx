import { DescriptionList } from '@/components/shared/description-list';
import { showToast } from '@/components/shared/toaster';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { formatDateTime } from '@/lib/country';
import { sendJson } from '@/lib/http';
import { Link } from '@inertiajs/react';
import { Copy, LoaderCircle, RotateCcw, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { type FailedJobDetail, type FailedJobRow } from './types';

interface FailedJobDialogProps {
    job: FailedJobRow | null;
    onOpenChange: (open: boolean) => void;
    onRetry: (job: FailedJobRow) => Promise<void>;
    onDelete: (job: FailedJobRow) => void;
}

/** The whole failure of one job (secrets removed on the server), with Retry and Delete. */
export function FailedJobDialog({ job, onOpenChange, onRetry, onDelete }: FailedJobDialogProps) {
    const [detail, setDetail] = useState<FailedJobDetail | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [retrying, setRetrying] = useState(false);

    useEffect(() => {
        if (!job) {
            return;
        }
        const controller = new AbortController();
        setDetail(null);
        setError(null);
        sendJson<FailedJobDetail>('GET', route('admin.sync-jobs.failed-jobs.show', job.uuid), undefined, controller.signal)
            .then((result) => (result.ok ? setDetail(result.data) : setError(result.message)))
            .catch(() => undefined);

        return () => controller.abort();
    }, [job]);

    const copy = () => {
        if (!detail) {
            return;
        }
        navigator.clipboard
            ?.writeText(detail.exception)
            .then(() => showToast('Error copied', 'success'))
            .catch(() => showToast('Could not copy. Select the text instead.', 'error'));
    };

    const retry = async () => {
        if (!job) {
            return;
        }
        setRetrying(true);
        await onRetry(job);
        setRetrying(false);
        onOpenChange(false);
    };

    return (
        <Dialog open={job !== null} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle className="break-all">{job?.job ?? 'Failed job'}</DialogTitle>
                    <DialogDescription className="font-mono text-xs break-all">{job?.jobClass}</DialogDescription>
                </DialogHeader>

                {job && (
                    <DescriptionList
                        columns={3}
                        items={[
                            {
                                label: 'Business',
                                value: job.business ? (
                                    <Link href={route('admin.tenants.show', job.business.id)} className="text-primary font-medium hover:underline">
                                        {job.business.name}
                                    </Link>
                                ) : (
                                    'Not linked to a business'
                                ),
                            },
                            { label: 'Queue', value: `${job.queue} (${job.connection})` },
                            { label: 'Failed', value: job.failedAt ? formatDateTime(job.failedAt, { second: '2-digit' }) : null },
                            { label: 'Attempts', value: job.attempts === null ? null : String(job.attempts) },
                            { label: 'Tries allowed', value: detail?.maxTries ? String(detail.maxTries) : detail ? 'Queue default' : '…' },
                            { label: 'Job ID', value: job.uuid, mono: true },
                            {
                                label: 'Queued with',
                                wide: true,
                                value: detail?.records.length
                                    ? detail.records.map((r) => `${r.type} ${r.id}`).join(', ')
                                    : detail
                                      ? 'No records'
                                      : '…',
                            },
                        ]}
                    />
                )}

                <div className="space-y-2">
                    <div className="flex items-center justify-between gap-2">
                        <h3 className="text-sm font-semibold">Error</h3>
                        <Button variant="ghost" size="sm" onClick={copy} disabled={!detail}>
                            <Copy />
                            Copy
                        </Button>
                    </div>
                    {error ? (
                        <p className="text-destructive text-sm">{error}</p>
                    ) : detail ? (
                        <>
                            <pre className="bg-muted text-foreground max-h-80 overflow-auto rounded-md p-3 font-mono text-xs leading-relaxed break-words whitespace-pre-wrap">
                                {detail.exception || 'No error text was stored.'}
                            </pre>
                            <p className="text-muted-foreground text-xs">
                                Keys, tokens and passwords are removed before the error is shown.
                                {detail.exceptionTruncated && ' Only the start of a very long error is shown.'}
                            </p>
                        </>
                    ) : (
                        <div className="space-y-2" aria-label="Loading the error">
                            <Skeleton className="h-4 w-3/4" />
                            <Skeleton className="h-4 w-full" />
                            <Skeleton className="h-4 w-5/6" />
                        </div>
                    )}
                </div>

                <DialogFooter className="gap-2 sm:justify-between">
                    <Button variant="outline" className="text-destructive" onClick={() => job && onDelete(job)}>
                        <Trash2 />
                        Delete
                    </Button>
                    <Button onClick={retry} disabled={retrying}>
                        {retrying ? <LoaderCircle className="animate-spin" /> : <RotateCcw />}
                        Retry job
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
