import { Button } from '@/components/ui/button';
import { formatDate, formatMoney } from '@/lib/country';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Landmark, ReceiptText } from 'lucide-react';

/** Module 1.13: the Direct Debit is not set up yet (UK only). */
export interface DirectDebitNotice {
    kind: 'directDebit';
    deadline: string;
    daysLeft: number;
    passed: boolean;
    /** The user may set the Direct Debit up (billing.manage: the owner). */
    canSetUp: boolean;
    url: string;
}

/** Audit UI-5: an invoice is overdue; the business is suspended (tills locked) on `lockOn` unless it is paid. */
export interface OverdueNotice {
    kind: 'overdue';
    /** Decimal string in the instance's currency ("60.00"). */
    amountDue: string;
    invoices: number;
    /** Calendar day ("2026-11-19") billing:run suspends the business; null when no automatic lock is coming. */
    lockOn: string | null;
    daysLeft: number | null;
    /** The user may see and settle the business's billing (billing.manage: the owner). */
    canPay: boolean;
    url: string;
}

export type BillingNotice = DirectDebitNotice | OverdueNotice;

function daysText(days: number): string {
    if (days <= 0) {
        return 'your tills lock today';
    }

    return `${days} ${days === 1 ? 'day' : 'days'} left before your tills lock`;
}

function lockText(notice: OverdueNotice): string | null {
    if (notice.lockOn === null || notice.daysLeft === null) {
        return null;
    }
    if (notice.daysLeft <= 0) {
        return 'your tills lock today';
    }
    if (notice.daysLeft === 1) {
        return 'your tills lock tomorrow';
    }

    return `your tills lock on ${formatDate(notice.lockOn)}`;
}

function Banner({
    urgent,
    icon: Icon,
    title,
    text,
    action,
}: {
    urgent: boolean;
    icon: typeof Landmark;
    title: string;
    text: string;
    action?: { label: string; url: string };
}) {
    return (
        <div
            role="status"
            className={cn(
                'flex flex-col gap-3 rounded-xl border px-4 py-3 sm:flex-row sm:items-center sm:justify-between',
                urgent ? 'border-danger/30 bg-danger-soft' : 'border-warning/30 bg-warning-soft',
            )}
        >
            <div className="flex items-start gap-3">
                <Icon className={cn('mt-0.5 size-5 shrink-0', urgent ? 'text-danger-foreground' : 'text-warning-foreground')} aria-hidden />
                <div className="grid gap-0.5 text-sm">
                    <p className="text-foreground font-semibold">{title}</p>
                    <p className="text-muted-foreground">{text}</p>
                </div>
            </div>
            {action && (
                <Button size="sm" asChild className="shrink-0 self-start sm:self-center">
                    <Link href={action.url}>{action.label}</Link>
                </Button>
            )}
        </div>
    );
}

function OverdueBanner({ notice }: { notice: OverdueNotice }) {
    const amount = formatMoney(notice.amountDue);
    const lock = lockText(notice);
    const what = notice.invoices === 1 ? 'the invoice' : `the ${notice.invoices} invoices`;

    return (
        <Banner
            urgent
            icon={ReceiptText}
            title={lock ? `${amount} is overdue — ${lock}` : `${amount} is overdue`}
            text={
                notice.canPay
                    ? `Pay ${what} to keep your tills trading. Billing shows what is owed and how to pay.`
                    : `Ask the business owner to pay ${what} from Billing in the portal${lock ? ' to keep your tills trading' : ''}.`
            }
            action={notice.canPay ? { label: 'Go to Billing', url: notice.url } : undefined}
        />
    );
}

function DirectDebitBanner({ notice }: { notice: DirectDebitNotice }) {
    return (
        <Banner
            urgent={notice.passed || notice.daysLeft <= 1}
            icon={Landmark}
            title={notice.passed ? 'Set up your Direct Debit to unlock your tills' : `Set up your Direct Debit — ${daysText(notice.daysLeft)}`}
            text={
                notice.canSetUp
                    ? 'It takes about two minutes on the secure GoCardless page.'
                    : 'Ask the business owner to set it up from Billing in the portal.'
            }
            action={notice.canSetUp ? { label: 'Set up Direct Debit', url: notice.url } : undefined}
        />
    );
}

/**
 * Across the business portal (hidden on the Billing page itself): until a Direct Debit exists (module 1.13), and while
 * an invoice is overdue, with the day the tills lock (audit UI-5). Owners (billing.manage) get the button to Billing;
 * everyone else is asked to tell the owner.
 */
export function AppBillingBanner() {
    const { billingNotice } = usePage<SharedData & { billingNotice: BillingNotice | null }>().props;
    const path = usePage().url.split('?')[0];

    if (!billingNotice || path === '/app/billing') {
        return null;
    }

    return billingNotice.kind === 'overdue' ? <OverdueBanner notice={billingNotice} /> : <DirectDebitBanner notice={billingNotice} />;
}

export default AppBillingBanner;
