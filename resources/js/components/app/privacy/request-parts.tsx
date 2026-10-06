import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { zonedDateFormat } from '@/lib/country';
import { router } from '@inertiajs/react';
import { CircleCheck, Store } from 'lucide-react';
import { type DataRequestRow } from './types';

const shopDateTime = () =>
    zonedDateFormat('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

const shopDate = () => zonedDateFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });

/** "1 Oct 2026, 14:30" in shop time; "" when missing. */
export function when(iso: string | null | undefined): string {
    return iso ? shopDateTime().format(new Date(iso)) : '';
}

/** "1 Oct 2026" in shop time; "" when missing. */
export function day(iso: string | null | undefined): string {
    return iso ? shopDate().format(new Date(iso)) : '';
}

export function RequestStatus({ row }: { row: Pick<DataRequestRow, 'status' | 'statusLabel'> }) {
    return <StatusPill tone={row.status === 'completed' ? 'success' : 'warning'}>{row.statusLabel}</StatusPill>;
}

export function RequestType({ row }: { row: Pick<DataRequestRow, 'type' | 'typeLabel'> }) {
    return <StatusPill tone={row.type === 'erasure' ? 'violet' : 'info'}>{row.typeLabel}</StatusPill>;
}

/**
 * What must still be done on the tills for an erasure (till-owned records the portal cannot change), and the
 * owner's "Done on the tills" confirmation.
 */
export function TillStepList({ row }: { row: DataRequestRow }) {
    if (row.tillSteps.length === 0) {
        return null;
    }

    return (
        <div className="bg-warning-soft/40 border-warning/30 grid gap-3 rounded-lg border p-4 text-sm">
            <p className="font-medium">Still to do on the tills</p>
            <ul className="grid gap-2">
                {row.tillSteps.map((step) => (
                    <li key={step.key} className="grid gap-0.5">
                        <span>{step.text}</span>
                        {step.shops.length > 0 && (
                            <span className="text-muted-foreground flex items-center gap-1 text-xs">
                                <Store className="size-3" aria-hidden />
                                {step.shops.join(', ')}
                            </span>
                        )}
                    </li>
                ))}
            </ul>
            {row.status === 'tillPending' ? (
                <ConfirmDialog
                    trigger={
                        <Button variant="outline" size="sm" className="justify-self-start">
                            <CircleCheck />
                            Done on the tills
                        </Button>
                    }
                    title="Mark this request completed?"
                    description="Confirm the details were cleared on every till listed. This is recorded in the activity log."
                    confirmLabel="Mark completed"
                    onConfirm={() =>
                        new Promise((resolve) =>
                            router.post(route('app.privacy.requests.till-done', row.id), {}, { preserveScroll: true, onFinish: () => resolve(null) }),
                        )
                    }
                />
            ) : (
                <p className="text-muted-foreground text-xs">Marked done {when(row.completedAt)}.</p>
            )}
        </div>
    );
}
