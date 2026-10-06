import { SectionCard } from '@/components/shared/section-card';
import { pillToneClasses, StatusPill, type StatusTone } from '@/components/shared/status-badge';
import { dateFormat } from '@/lib/country';
import { cn } from '@/lib/utils';
import { AlertTriangle, CalendarClock, CheckCircle2, Clock3, Hourglass, Landmark, Lock, ReceiptText, XCircle } from 'lucide-react';
import { type ComponentType, type ReactNode } from 'react';

export type BillingState =
    | 'paid'
    | 'trial'
    | 'waitingForDirectDebit'
    | 'paymentFailed'
    | 'overdue'
    | 'setupFeeDue'
    | 'instalmentDue'
    | 'suspended'
    | 'cancelled';

export type BillingStateGroup = 'paid' | 'trial' | 'waitingForDirectDebit' | 'overdue' | 'setupDue' | 'suspended' | 'cancelled';

export type BillingStatusAction = 'recordSetupPayment' | 'sendDirectDebitLink' | 'retryPayment' | 'recordPayment';

interface BillingStatusLine {
    text: string;
    status: string;
    tone: StatusTone;
}

/** BillingStatusData (PHP): one business's billing in plain words. */
export interface BillingStatusData {
    state: BillingState;
    group: BillingStateGroup;
    tone: StatusTone;
    headline: string;
    daysLeft: number | null;
    locksOn: string | null;
    planType: { value: string; label: string } | null;
    setupFee: BillingStatusLine;
    recurring: BillingStatusLine;
    next: { text: string; date: string | null } | null;
    action: BillingStatusAction | null;
    actionLabel: string | null;
    failedPaymentId: string | null;
    demo: boolean;
}

const icons: Record<BillingState, ComponentType<{ className?: string }>> = {
    paid: CheckCircle2,
    trial: Hourglass,
    waitingForDirectDebit: Clock3,
    paymentFailed: XCircle,
    overdue: AlertTriangle,
    setupFeeDue: Lock,
    instalmentDue: ReceiptText,
    suspended: Lock,
    cancelled: XCircle,
};

const accent: Record<StatusTone, string> = {
    success: 'border-l-success',
    warning: 'border-l-warning',
    danger: 'border-l-danger',
    info: 'border-l-info',
    violet: 'border-l-violet',
    neutral: 'border-l-border-strong',
};

function day(date: string): string {
    return dateFormat({ day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));
}

function Line({ icon: Icon, label, line }: { icon: ComponentType<{ className?: string }>; label: string; line: BillingStatusLine }) {
    return (
        <div className="flex items-start gap-3 rounded-lg border p-4">
            <span className={cn('mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full', pillToneClasses[line.tone])}>
                <Icon className="size-4" aria-hidden />
            </span>
            <span className="grid min-w-0 gap-0.5">
                <span className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{label}</span>
                <span className="text-sm leading-snug font-medium">{line.text}</span>
            </span>
        </div>
    );
}

interface BillingStatusCardProps {
    status: BillingStatusData;
    /** The next action button (each page wires its own: record a payment, send the link, retry…). */
    action?: ReactNode;
    /** "Monthly fee" or "Yearly fee". */
    recurringLabel?: string;
    className?: string;
}

/**
 * "Billing status" (admin tenant Billing tab and the portal's My subscription): the plan type, the setup fee and how
 * it was paid, the recurring fee and its Direct Debit, the state in a few words and what happens next with the date.
 */
export function BillingStatusCard({ status, action, recurringLabel = 'Monthly fee', className }: BillingStatusCardProps) {
    const Icon = icons[status.state];

    return (
        <SectionCard
            className={cn('border-l-4', accent[status.tone], className)}
            title={
                <span className="inline-flex flex-wrap items-center gap-2">
                    Billing status
                    {status.demo && <StatusPill tone="violet">Demo business</StatusPill>}
                </span>
            }
            description={status.planType ? `Plan type: ${status.planType.label}` : 'No plan yet'}
            actions={action}
        >
            <div className="grid gap-5">
                <div className="flex items-center gap-3">
                    <span className={cn('flex size-10 shrink-0 items-center justify-center rounded-full', pillToneClasses[status.tone])}>
                        <Icon className="size-5" aria-hidden />
                    </span>
                    <p className="text-lg leading-tight font-semibold tracking-tight" data-testid="billing-status-headline">
                        {status.headline}
                    </p>
                </div>

                <div className="grid gap-3 md:grid-cols-2">
                    <Line icon={ReceiptText} label="Setup fee" line={status.setupFee} />
                    <Line icon={Landmark} label={recurringLabel} line={status.recurring} />
                </div>

                {status.next && (
                    <div className="bg-subtle flex items-start gap-3 rounded-lg p-4">
                        <CalendarClock className="text-muted-foreground mt-0.5 size-4 shrink-0" aria-hidden />
                        <div className="grid gap-1 text-sm">
                            <span className="flex flex-wrap items-center gap-2 font-medium">
                                What happens next
                                {status.next.date && <StatusPill tone={status.tone}>{day(status.next.date)}</StatusPill>}
                            </span>
                            <span className="text-muted-foreground leading-relaxed">{status.next.text}</span>
                        </div>
                    </div>
                )}

                {status.demo && (
                    <p className="text-muted-foreground text-xs">
                        Demo business: nothing is ever sent to GoCardless for it and it never gets an email (logged as “Not sent (demo)”).
                    </p>
                )}
            </div>
        </SectionCard>
    );
}

export default BillingStatusCard;
