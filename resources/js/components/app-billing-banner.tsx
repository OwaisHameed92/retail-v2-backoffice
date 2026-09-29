import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Landmark } from 'lucide-react';

export interface BillingNotice {
    deadline: string;
    daysLeft: number;
    passed: boolean;
    /** The user may set the Direct Debit up (billing.manage: the owner). */
    canSetUp: boolean;
    url: string;
}

function daysText(days: number): string {
    if (days <= 0) {
        return 'your tills lock today';
    }

    return `${days} ${days === 1 ? 'day' : 'days'} left before your tills lock`;
}

/**
 * Module 1.13: across the business portal until a Direct Debit exists. Owners (billing.manage) get the button to the
 * Billing page; everyone else is asked to tell the owner. Hidden on the Billing page itself.
 */
export function AppBillingBanner() {
    const { billingNotice } = usePage<SharedData & { billingNotice: BillingNotice | null }>().props;
    const path = usePage().url.split('?')[0];

    if (!billingNotice || path === '/app/billing') {
        return null;
    }

    const urgent = billingNotice.passed || billingNotice.daysLeft <= 1;

    return (
        <div
            role="status"
            className={cn(
                'flex flex-col gap-3 rounded-xl border px-4 py-3 sm:flex-row sm:items-center sm:justify-between',
                urgent ? 'border-danger/30 bg-danger-soft' : 'border-warning/30 bg-warning-soft',
            )}
        >
            <div className="flex items-start gap-3">
                <Landmark className={cn('mt-0.5 size-5 shrink-0', urgent ? 'text-danger-foreground' : 'text-warning-foreground')} aria-hidden />
                <div className="grid gap-0.5 text-sm">
                    <p className="text-foreground font-semibold">
                        {billingNotice.passed
                            ? 'Set up your Direct Debit to unlock your tills'
                            : `Set up your Direct Debit — ${daysText(billingNotice.daysLeft)}`}
                    </p>
                    <p className="text-muted-foreground">
                        {billingNotice.canSetUp
                            ? 'It takes about two minutes on the secure GoCardless page.'
                            : 'Please ask the business owner to set it up from Billing in the portal.'}
                    </p>
                </div>
            </div>
            {billingNotice.canSetUp && (
                <Button size="sm" asChild className="shrink-0 self-start sm:self-center">
                    <Link href={billingNotice.url}>Set up Direct Debit</Link>
                </Button>
            )}
        </div>
    );
}

export default AppBillingBanner;
