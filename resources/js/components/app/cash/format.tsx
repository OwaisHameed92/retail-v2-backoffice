import { StatusBadge, type StatusToneMap } from '@/components/shared/status-badge';
import { money } from '@/components/shared/trading/format';
import { cn } from '@/lib/utils';
import { TriangleAlert } from 'lucide-react';

export { formatDateTime, formatDay } from '@/components/app/pricing/format';
export { money, number } from '@/components/shared/trading/format';

export const dash = <span className="text-muted-foreground">—</span>;

/** A difference: red when short (negative), green when over, "£0.00" plain; null → "—". Real minus sign. */
export function Variance({ value, className }: { value: string | null | undefined; className?: string }) {
    if (value === null || value === undefined || value === '') {
        return dash;
    }
    const n = Number(value);
    const text = `${n < 0 ? '−' : n > 0 ? '+' : ''}${money(Math.abs(n))}`;

    return (
        <span className={cn('tabular-nums', n < 0 && 'text-danger-foreground font-medium', n > 0 && 'text-success-foreground', className)}>
            {text}
        </span>
    );
}

/** Money right-aligned in tables; null → "—". */
export function Money({ value, className }: { value: string | null | undefined; className?: string }) {
    return value === null || value === undefined || value === '' ? dash : <span className={cn('tabular-nums', className)}>{money(value)}</span>;
}

/** The till's own "over the alert amount" flag. */
export function AlertFlag({ label = 'Over threshold' }: { label?: string }) {
    return (
        <span className="text-danger-foreground inline-flex items-center gap-1 text-xs font-medium">
            <TriangleAlert className="size-3.5" aria-hidden />
            {label}
        </span>
    );
}

/** `CashMovementType` (enums.json) in words. */
export const MOVEMENT_TYPES: Record<string, string> = {
    openingFloat: 'Opening float',
    paidIn: 'Paid in',
    paidOut: 'Paid out',
    safeDrop: 'Safe drop',
    bankDrop: 'Bank drop',
    floatTopUp: 'Float top-up',
    closingCount: 'Closing count',
    sale: 'Sale',
    refund: 'Refund',
    counterfeit: 'Counterfeit',
    pettyCashOut: 'Petty cash out',
    pettyCashIn: 'Petty cash in',
    changeOrderReceived: 'Change order received',
    cashReconciliationVariance: 'Cash-up difference',
    safeBankDrop: 'Safe to bank',
    voucherSale: 'Voucher sale',
    accountPayment: 'Account payment',
    customerAdvance: 'Customer advance',
    customerAdvanceRefund: 'Advance refunded',
};

export const movementLabel = (type: string | null) => (type ? (MOVEMENT_TYPES[type] ?? type) : 'Unknown');

const SHIFT_TONES: StatusToneMap = { open: 'info', closed: 'neutral' };

export function ShiftStatus({ status }: { status: string | null }) {
    return (
        <StatusBadge
            status={status ?? 'unknown'}
            label={status === 'open' ? 'Open' : status === 'closed' ? 'Closed' : 'Unknown'}
            tones={SHIFT_TONES}
        />
    );
}

export const BANKING_STATUS: Record<string, { label: string; tone: 'success' | 'warning' | 'info' | 'neutral' }> = {
    prepared: { label: 'Prepared', tone: 'warning' },
    inTransit: { label: 'In transit', tone: 'info' },
    banked: { label: 'Banked', tone: 'success' },
    cancelled: { label: 'Cancelled', tone: 'neutral' },
};

export const STAGE_LABELS: Record<string, string> = { open: 'Opening count', close: 'Closing count', spot: 'Spot check' };
