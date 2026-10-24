import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { FormField } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatMoney } from '@/lib/country';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { LoaderCircle, UserX, X } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';
import { CANCEL_REASONS, nextStep } from './format';
import { type OrderCard, type OrderStatus } from './types';

type ActionOrder = Pick<OrderCard, 'id' | 'reference' | 'status' | 'customer' | 'fulfilment' | 'payment' | 'total'>;

const post = (order: ActionOrder, status: OrderStatus, setBusy: (busy: boolean) => void) =>
    new Promise<void>((resolve) =>
        router.post(
            route('app.online-orders.status', order.id),
            { status },
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

/**
 * The order's one main move (Start picking, Mark ready / packed, Collected, Out for delivery, Delivered), "Not
 * collected" once a collection is ready (OSA-21), and Cancel (asks for the reason the customer is told, or cancels as
 * spam without an email, OSA-1). Final moves ask first. Nothing for a finished order.
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
    const step = nextStep(order.status, order.fulfilment);

    if (!step) {
        return null;
    }

    const delivery = order.fulfilment === 'delivery';
    const move = () => post(order, step.to, setBusy);
    const Icon = step.icon;
    const main = (
        <Button size={size} disabled={busy} className={cn(stretch && 'flex-1')} onClick={step.confirm ? undefined : () => void move()}>
            {busy ? <LoaderCircle className="size-4 animate-spin" aria-hidden /> : <Icon className="size-4" aria-hidden />}
            {step.label}
        </Button>
    );
    const missed = order.status === 'ready' && !delivery ? 'Not collected' : order.status === 'outForDelivery' ? 'Not delivered' : null;
    const takeNote = order.payment.paid
        ? 'It was paid by card online: the till must not take the payment again.'
        : 'Ring the order up on the till as a normal sale: the till takes the payment and the stock off.';

    return (
        <div className={cn('flex items-center gap-2', className)}>
            {step.confirm ? (
                <ConfirmDialog
                    trigger={main}
                    title={`Mark ${order.reference} as ${delivery ? 'delivered' : 'collected'}?`}
                    description={`${takeNote} You can undo this for a few minutes.`}
                    confirmLabel={delivery ? 'Mark delivered' : 'Mark collected'}
                    onConfirm={move}
                />
            ) : (
                main
            )}
            {missed && (
                <ConfirmDialog
                    trigger={
                        <Button size={size} variant="outline" disabled={busy} aria-label={`${missed}: ${order.reference}`}>
                            <UserX className="size-4" aria-hidden />
                            <span className={cn(stretch && 'sr-only sm:not-sr-only')}>{missed}</span>
                        </Button>
                    }
                    title={`Mark ${order.reference} as ${missed.toLowerCase()}?`}
                    description={`It moves to Done.${order.payment.paid ? ' It was paid by card: refund it in your DNA portal if you do not hand it over later.' : ''}`}
                    confirmLabel={`Mark ${missed.toLowerCase()}`}
                    onConfirm={() => post(order, 'notCollected', setBusy)}
                />
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
    const { data, setData, post: send, processing, errors } = useForm({ status: 'cancelled', reason: '', spam: false as boolean });
    const tooShort = !data.spam && data.reason.trim().length < 3;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        send(route('app.online-orders.status', order.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>Cancel order {order.reference}?</DialogTitle>
                        <DialogDescription>
                            {data.spam ? 'Nobody is emailed. ' : `${order.customer} is emailed straight away with your reason. `}
                            {order.payment.paid
                                ? `It was paid by card online (${formatMoney(order.total)}): cancelling does not refund it. Refund it in your DNA portal.`
                                : 'Nothing was paid online, so there is nothing to refund.'}
                        </DialogDescription>
                    </DialogHeader>
                    {!data.spam && (
                        <>
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
                        </>
                    )}
                    <div className="flex items-start gap-3">
                        <Checkbox
                            id={`cancel-spam-${order.id}`}
                            checked={data.spam}
                            onCheckedChange={(on) => setData('spam', on === true)}
                            className="mt-0.5"
                        />
                        <div className="grid gap-0.5">
                            <Label htmlFor={`cancel-spam-${order.id}`}>This is a fake order</Label>
                            <p className="text-muted-foreground text-xs">Frees its slot without emailing the address it was placed with.</p>
                        </div>
                    </div>
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
