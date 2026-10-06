import { StatusPill, type StatusTone } from '@/components/shared/status-badge';
import { money, number } from '@/components/shared/trading/format';
import { zonedDateFormat } from '@/lib/country';
import { cn } from '@/lib/utils';
import { type ConsentState, type Option } from './types';

const shopDate = () => zonedDateFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });

/** "7 Oct 2026" in shop time. */
export function dayLabel(iso: string | null | undefined): string {
    return iso ? shopDate().format(new Date(iso)) : '';
}

export { money, number };

/** Positive = the customer owes; negative = in credit. */
export function balanceTone(balance: string): 'owes' | 'credit' | 'clear' {
    const value = Number(balance);
    return value > 0 ? 'owes' : value < 0 ? 'credit' : 'clear';
}

/** A balance with its meaning: "£12.40 owed", "£4.00 credit held" (paid in advance), "£0.00". */
export function BalanceText({ balance, className, short = false }: { balance: string; className?: string; short?: boolean }) {
    const tone = balanceTone(balance);
    const amount = money(Math.abs(Number(balance)));

    return (
        <span
            className={cn(
                'tabular-nums',
                tone === 'owes' && 'text-warning-foreground font-medium',
                tone === 'credit' && 'text-success-foreground',
                tone === 'clear' && 'text-muted-foreground',
                className,
            )}
        >
            {amount}
            {!short && tone === 'owes' && <span className="text-muted-foreground ml-1 text-xs font-normal">owed</span>}
            {!short && tone === 'credit' && <span className="text-muted-foreground ml-1 text-xs font-normal">credit held</span>}
        </span>
    );
}

/** A signed ledger amount: "+£8.40" (adds to what is owed) or "−£5.00" (a payment or refund). */
export function SignedAmount({ value, points = false }: { value: string | number; points?: boolean }) {
    const n = Number(value);
    if (n === 0) {
        return <span className="text-muted-foreground">—</span>;
    }
    const text = points ? number(Math.abs(n)) : money(Math.abs(n));

    return (
        <span className={cn('tabular-nums', n < 0 ? 'text-success-foreground' : 'text-foreground')}>
            {n < 0 ? '−' : '+'}
            {text}
        </span>
    );
}

const consentTones: Record<ConsentState['state'], StatusTone> = { given: 'success', withdrawn: 'danger', none: 'neutral' };
const consentLabels: Record<ConsentState['state'], string> = { given: 'Opted in', withdrawn: 'Opted out', none: 'Not asked' };

export function ConsentPill({ state }: { state: ConsentState['state'] }) {
    return <StatusPill tone={consentTones[state]}>{consentLabels[state]}</StatusPill>;
}

export const CONSENT_OPTIONS: Option[] = [
    { value: 'email', label: 'Email' },
    { value: 'sms', label: 'Text message' },
    { value: 'whatsApp', label: 'WhatsApp' },
    { value: 'post', label: 'Post' },
];

export const BALANCE_OPTIONS: Option[] = [
    { value: 'owes', label: 'Owes money' },
    { value: 'credit', label: 'Credit held' },
    { value: 'overLimit', label: 'Over credit limit' },
];

export const STATUS_OPTIONS: Option[] = [
    { value: 'inactive', label: 'Inactive only' },
    { value: 'everyone', label: 'Active and inactive' },
];
