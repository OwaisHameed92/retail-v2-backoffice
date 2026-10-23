import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { FormField } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { LoaderCircle, X } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';
import { CANCEL_REASONS, NEXT_STEP } from './format';
import { type OrderCard } from './types';

type ActionOrder = Pick<OrderCard, 'id' | 'reference' | 'status' | 'customer'>;

/**
 * The order's one main move (Start picking, Mark ready, Collected) and Cancel (asks for the reason the customer is
 * told). "Collected" is final, so it asks first. Nothing for a collected or cancelled order.
 */
export function OrderActions({
    order,
    size = 'sm',
    className,
    stretch = false,
}: {
    order: ActionOrder;
    size?: 'sm' | 'default';
    className?: string;
    stretch?: boolean;
}) {
    const [busy, setBusy] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const step = NEXT_STEP[order.status];

    if (!step) {
        return null;
    }

    const move = () =>
        new Promise<void>((resolve) =>
            router.post(
                route('app.online-orders.status', order.id),
                { status: step.to },
                {
                    preserveScroll: true,
                    onStart: () => setBusy(true),
                    onFinish: () => {
                        setBusy(false);
                        resolve();
                    },
                },
            ),
        );
    const Icon = step.icon;
    const main = (
        <Button size={size} disabled={busy} className={cn(stretch && 'flex-1')} onClick={step.to === 'collected' ? undefined : () => void move()}>
            {busy ? <LoaderCircle className="size-4 animate-spin" aria-hidden /> : <Icon className="size-4" aria-hidden />}
            {step.label}
        </Button>
    );

    return (
        <div className={cn('flex items-center gap-2', className)}>
            {step.to === 'collected' ? (
                <ConfirmDialog
                    trigger={main}
                    title={`Mark ${order.reference} as collected?`}
                    description={`Ring the order up on the till as a normal sale: the till takes the payment and the stock off. A collected order cannot be moved back.`}
                    confirmLabel="Mark collected"
                    onConfirm={move}
                />
            ) : (
                main
            )}
            <Button size={size} variant="outline" disabled={busy} onClick={() => setCancelling(true)} aria-label={`Cancel ${order.reference}`}>
                <X className="size-4" aria-hidden />
                <span className={cn(stretch && 'sr-only sm:not-sr-only')}>Cancel</span>
            </Button>
            {cancelling && <CancelDialog order={order} onClose={() => setCancelling(false)} />}
        </div>
    );
}

function CancelDialog({ order, onClose }: { order: ActionOrder; onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({ status: 'cancelled', reason: '' });
    const tooShort = data.reason.trim().length < 3;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('app.online-orders.status', order.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>Cancel order {order.reference}?</DialogTitle>
                        <DialogDescription>
                            {order.customer} is emailed straight away with your reason. Nothing was paid, so there is nothing to refund. A cancelled
                            order cannot be reopened.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex flex-wrap gap-2">
                        {CANCEL_REASONS.map((reason) => (
                            <Button
                                key={reason}
                                type="button"
                                size="sm"
                                variant="secondary"
                                className="h-auto py-1 text-xs"
                                onClick={() => setData('reason', reason)}
                            >
                                {reason}
                            </Button>
                        ))}
                    </div>
                    <FormField
                        id={`cancel-reason-${order.id}`}
                        label="Reason for the customer"
                        error={errors.reason ?? errors.status}
                        help="Written to the customer as it is."
                    >
                        <Textarea
                            id={`cancel-reason-${order.id}`}
                            rows={3}
                            maxLength={255}
                            required
                            value={data.reason}
                            aria-invalid={!!errors.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                        />
                    </FormField>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Keep the order
                        </Button>
                        <Button type="submit" variant="destructive" disabled={processing || tooShort}>
                            {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden />}
                            Cancel order
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
