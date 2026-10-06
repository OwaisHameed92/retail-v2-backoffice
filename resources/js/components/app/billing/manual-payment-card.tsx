import { formatDay } from '@/components/admin/billing/format';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Banknote, CheckCircle2, Landmark, Smartphone, TriangleAlert } from 'lucide-react';
import { type ReactNode } from 'react';
import { type ManualPayment } from './types';

function Account({ icon: Icon, title, children }: { icon: typeof Landmark; title: string; children: ReactNode }) {
    return (
        <div className="grid content-start gap-1.5 rounded-lg border p-4">
            <span className="text-muted-foreground inline-flex items-center gap-2 text-xs font-medium tracking-wide uppercase">
                <Icon className="size-4" aria-hidden />
                {title}
            </span>
            <div className="grid gap-0.5 text-sm tabular-nums">{children}</div>
        </div>
    );
}

/**
 * Pakistan plan P5 (manual collection): "How to pay" on My subscription, in place of the Direct Debit card. What is
 * owed now, the next invoice, and the accounts to pay into (bank account, JazzCash, Easypaisa); each one shows only
 * when it is set up on this instance.
 */
export function ManualPaymentCard({ payment }: { payment: ManualPayment }) {
    const { next } = payment;
    const reference = payment.reference ?? 'your invoice number';
    const hasAccounts = payment.bank.length > 0 || payment.jazzCash !== null || payment.easypaisa !== null;

    return (
        <SectionCard
            title="How to pay"
            description={`We email you an invoice for each period. Pay it by ${payment.methodsText}, quoting the invoice number as the reference. Your tills are renewed as soon as we record the payment.`}
            actions={
                payment.hasAmountDue ? (
                    <StatusPill tone={payment.overdue ? 'danger' : 'warning'}>{payment.overdue ? 'Overdue' : 'To pay'}</StatusPill>
                ) : (
                    <StatusPill tone="success">Nothing to pay</StatusPill>
                )
            }
        >
            <div className="grid gap-5">
                {next ? (
                    <Alert variant={next.overdue ? 'destructive' : 'default'}>
                        <TriangleAlert className="size-4" />
                        <AlertTitle>
                            {next.overdue ? `${next.number} is overdue: ${next.balance} to pay` : `${next.balance} due on ${formatDay(next.dueDate)}`}
                        </AlertTitle>
                        <AlertDescription>
                            {payment.hasAmountDue && payment.amountDue !== next.balance ? `${payment.amountDue} owed in total. ` : ''}
                            Quote {reference} as the reference so we can match your payment.
                        </AlertDescription>
                    </Alert>
                ) : (
                    <div className="flex items-start gap-3 text-sm">
                        <CheckCircle2 className="text-success-foreground mt-0.5 size-5 shrink-0" aria-hidden />
                        <p>You have nothing to pay right now. We email your next invoice before your tills need renewing.</p>
                    </div>
                )}

                {hasAccounts ? (
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {payment.bank.length > 0 && (
                            <Account icon={Landmark} title="Bank transfer">
                                {payment.bank.map((line) => (
                                    <span key={line}>{line}</span>
                                ))}
                            </Account>
                        )}
                        {payment.jazzCash && (
                            <Account icon={Smartphone} title="JazzCash">
                                <span>{payment.jazzCash}</span>
                            </Account>
                        )}
                        {payment.easypaisa && (
                            <Account icon={Smartphone} title="Easypaisa">
                                <span>{payment.easypaisa}</span>
                            </Account>
                        )}
                        {payment.cash && (
                            <Account icon={Banknote} title="Cash">
                                <span className="text-muted-foreground">Pay our team in person and keep the receipt.</span>
                            </Account>
                        )}
                    </div>
                ) : (
                    <p className="text-muted-foreground text-sm">
                        Our account details are on each invoice. Reply to the invoice email if you need them again.
                    </p>
                )}
            </div>
        </SectionCard>
    );
}

export default ManualPaymentCard;
