import { StatusPill, type StatusTone } from '@/components/shared/status-badge';
import { money } from '@/components/shared/trading/format';
import { cn } from '@/lib/utils';

export { formatDateTime, formatDay } from '@/components/app/pricing/format';
export { money, number } from '@/components/shared/trading/format';

/** What a sale document is, in shop words. */
export function saleKind(type: string | null, status: string | null): { label: string; tone: StatusTone } {
    if (status === 'voided') {
        return { label: 'Voided', tone: 'danger' };
    }

    switch (type) {
        case 'refund':
            return { label: 'Refund', tone: 'warning' };
        case 'exchange':
            return { label: 'Exchange', tone: 'info' };
        case 'deposit':
            return { label: 'Order deposit', tone: 'violet' };
        default:
            return { label: 'Sale', tone: 'success' };
    }
}

export function SaleKindPill({ type, status, className }: { type: string | null; status: string | null; className?: string }) {
    const kind = saleKind(type, status);

    return (
        <StatusPill tone={kind.tone} className={className}>
            {kind.label}
        </StatusPill>
    );
}

/** Money with a proper minus sign; negatives (refunds) in the warning colour, voided baskets struck through. */
export function Amount({ value, voided = false, className }: { value: string; voided?: boolean; className?: string }) {
    const n = Number(value);

    return (
        <span className={cn('tabular-nums', n < 0 && 'text-warning-foreground', voided && 'text-muted-foreground line-through', className)}>
            {n < 0 ? `−${money(Math.abs(n))}` : money(n)}
        </span>
    );
}

/** A discount shown as a deduction: "−£0.50". */
export function deduction(value: string): string {
    const n = Math.abs(Number(value));

    return `−${money(n)}`;
}

export const DISCOUNT_SOURCE: Record<string, string> = {
    manual: 'Manual discount',
    staff: 'Staff discount',
    promotion: 'Promotion',
    customerGroup: 'Customer group price',
    coupon: 'Coupon',
};

export const LINE_FLAGS: Record<string, string> = {
    orderDeposit: 'Order deposit (not a sale)',
    charity: 'Charity round-up (not a sale)',
    bagCharge: 'Bag charge',
    weighed: 'Weighed',
    ageRestricted: 'Age checked',
    pmp: 'Price-marked pack',
    damaged: 'Damaged',
    restocked: 'Back to stock',
};

export const STATUS_OPTIONS = [
    { value: 'completed', label: 'Completed sales' },
    { value: 'refunds', label: 'Refunds' },
    { value: 'voided', label: 'Voided baskets' },
];

/** "0.0000" → "0", "20.0000" → "20", "17.5000" → "17.5". */
export function trimRate(rate: string): string {
    return String(Number(rate));
}

/** "2.0000" → "2", "0.3450" → "0.345" (weighed items). */
export function qty(value: string): string {
    return String(Number(value));
}

/** Audit action codes in plain words. */
export function actionLabel(action: string): string {
    const known: Record<string, string> = {
        LineVoided: 'Line voided',
        RefundApproved: 'Refund approved',
        SaleVoided: 'Basket voided',
        VoidSale: 'Basket voided',
        NoSale: 'Drawer opened (no sale)',
        PriceOverride: 'Price changed at the till',
        DiscountApplied: 'Discount given',
        CartCleared: 'Basket cleared before payment',
        AccountPaymentCollected: 'Account payment taken',
        AdvanceRefunded: 'Advance refunded',
        PaymentReminderSent: 'Payment reminder sent',
        PaymentReminderFailed: 'Payment reminder failed',
    };

    return known[action] ?? action.replace(/([a-z])([A-Z])/g, '$1 $2').replace(/^./, (c) => c.toUpperCase());
}
