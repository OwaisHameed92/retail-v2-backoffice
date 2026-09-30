import { formatDate } from '@/components/admin/billing/format';
import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { CheckCircle2, Landmark, LoaderCircle, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { type PortalBillingProps } from './types';

/** Module 1.13: the Direct Debit (mandate, deadline, owner's setup); 4.10 adds "Change bank account" (a request). */
export function DirectDebitCard({
    directDebit,
    pricing,
    onChangeBank,
}: Pick<PortalBillingProps, 'directDebit' | 'pricing'> & { onChangeBank?: () => void }) {
    const [starting, setStarting] = useState(false);
    const { mandate, deadline } = directDebit;

    const start = () => router.post(route('app.billing.direct-debit'), {}, { onStart: () => setStarting(true), onFinish: () => setStarting(false) });

    const button = directDebit.canSetUp && (
        <Button onClick={start} disabled={starting || !directDebit.available}>
            {starting ? <LoaderCircle className="size-4 animate-spin" /> : <Landmark />}
            Set up Direct Debit
        </Button>
    );

    return (
        <SectionCard
            title="Direct Debit"
            description={
                directDebit.directDebit
                    ? 'Your subscription is collected by Direct Debit through GoCardless, a secure payment provider.'
                    : 'You pay each invoice by cash or bank transfer.'
            }
            actions={mandate.usable ? <StatusBadge status="active" label="Active" /> : undefined}
            footer={
                mandate.usable && onChangeBank ? (
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-muted-foreground text-sm">Moving to a new bank account? We move your Direct Debit for you.</p>
                        <Button variant="outline" size="sm" onClick={onChangeBank}>
                            <Landmark />
                            Change bank account
                        </Button>
                    </div>
                ) : undefined
            }
        >
            {mandate.usable ? (
                <div className="flex items-start gap-3 text-sm">
                    <CheckCircle2 className="text-success-foreground mt-0.5 size-5 shrink-0" aria-hidden />
                    <p>
                        Your Direct Debit is set up{mandate.activeAt ? ` since ${formatDate(mandate.activeAt)}` : ''}. We collect{' '}
                        {pricing.isZero ? 'nothing each cycle for now' : `${pricing.gross} ${pricing.per}`} and email each invoice before it is taken.
                    </p>
                </div>
            ) : !directDebit.directDebit ? (
                <p className="text-muted-foreground text-sm">
                    Want to pay by Direct Debit instead? Contact Switch & Save and we will switch you over.
                </p>
            ) : (
                <div className="grid gap-4">
                    {mandate.lost ? (
                        <Alert variant="destructive">
                            <TriangleAlert className="size-4" />
                            <AlertTitle>Your Direct Debit has stopped ({mandate.statusLabel.toLowerCase()})</AlertTitle>
                            <AlertDescription>Set it up again so your payments keep going through.</AlertDescription>
                        </Alert>
                    ) : deadline ? (
                        <Alert variant={deadline.passed ? 'destructive' : 'default'}>
                            <TriangleAlert className="size-4" />
                            <AlertTitle>
                                {deadline.passed
                                    ? 'Your tills are locked until your Direct Debit is set up'
                                    : `${deadline.daysLeft} ${deadline.daysLeft === 1 ? 'day' : 'days'} left to set up your Direct Debit`}
                            </AlertTitle>
                            <AlertDescription>
                                {deadline.passed
                                    ? 'Set it up now and your tills unlock at their next check-in.'
                                    : `Please set it up by ${formatDate(deadline.deadline)}, or your tills lock until you do.`}
                            </AlertDescription>
                        </Alert>
                    ) : pricing.isZero ? (
                        <p className="text-muted-foreground text-sm">Nothing is charged each cycle, so you do not need a Direct Debit yet.</p>
                    ) : null}
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                        {button}
                        <p className="text-muted-foreground text-sm">
                            {directDebit.available
                                ? 'You will enter your bank details on GoCardless and come straight back here. It takes about two minutes.'
                                : 'Direct Debit setup is not available right now. Please try again later.'}
                        </p>
                    </div>
                </div>
            )}
        </SectionCard>
    );
}
