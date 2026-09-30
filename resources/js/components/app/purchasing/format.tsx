import { formatDateTime, formatDay } from '@/components/app/pricing/format';
import { PageTabs } from '@/components/shared/page-tabs';
import { StatusBadge, statusLabel, type StatusToneMap } from '@/components/shared/status-badge';
import { money } from '@/components/shared/trading/format';
import { type PurchasingKind } from './types';

export { formatDateTime, formatDay, money };

/** One tone map for every purchasing status (orders, deliveries, invoices, returns, credits, payments, rebates). */
export const PURCHASING_TONES: StatusToneMap = {
    draft: 'neutral',
    sent: 'info',
    partReceived: 'warning',
    received: 'success',
    invoiced: 'violet',
    paid: 'success',
    cancelled: 'neutral',
    posted: 'success',
    matched: 'info',
    approved: 'info',
    partPaid: 'warning',
    disputed: 'danger',
    credited: 'success',
    unused: 'info',
    used: 'neutral',
    current: 'success',
    reversed: 'neutral',
    active: 'success',
    inactive: 'neutral',
};

const LABELS: Record<string, string> = {
    partReceived: 'Part received',
    partPaid: 'Part paid',
    unused: 'Not yet used',
    used: 'Used',
    current: 'Paid',
};

export function PurchasingStatus({ status }: { status: string | null }) {
    return status ? (
        <StatusBadge status={status} tones={PURCHASING_TONES} label={LABELS[status]} />
    ) : (
        <span className="text-muted-foreground">—</span>
    );
}

export function statusText(status: string): string {
    return LABELS[status] ?? statusLabel(status);
}

export const KIND_LABELS: Record<PurchasingKind, string> = {
    orders: 'Orders',
    deliveries: 'Deliveries',
    invoices: 'Invoices',
    'credit-notes': 'Credit notes',
    returns: 'Returns',
    payments: 'Payments',
    rebates: 'Rebates',
};

/** Singular names for detail pages and links. */
export const KIND_SINGULAR: Record<PurchasingKind, string> = {
    orders: 'Purchase order',
    deliveries: 'Delivery',
    invoices: 'Supplier invoice',
    'credit-notes': 'Credit note',
    returns: 'Purchase return',
    payments: 'Payment',
    rebates: 'Rebate',
};

export const PAYMENT_METHODS: Record<string, string> = {
    bankTransfer: 'Bank transfer',
    cheque: 'Cheque',
    cash: 'Cash',
    card: 'Card',
    directDebit: 'Direct Debit',
    other: 'Other',
};

export const RETURN_REASONS: Record<string, string> = {
    damaged: 'Damaged',
    wrong: 'Wrong item',
    expired: 'Out of date',
    recalled: 'Recalled',
    other: 'Other',
};

/** "12" or "12.5" from a 4 dp quantity string. */
export function qty(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }
    const n = Number(value);

    return Number.isInteger(n) ? n.toLocaleString('en-GB') : n.toLocaleString('en-GB', { maximumFractionDigits: 4 });
}

/** A cost ex VAT with up to 4 decimal places ("£0.4575"), at least 2. */
export function cost(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return `£${Number(value).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 4 })}`;
}

export function documentHref(kind: PurchasingKind, id: string): string | null {
    if (kind === 'orders') {
        return route('app.purchasing.orders.show', id);
    }

    return ['deliveries', 'invoices', 'credit-notes', 'returns'].includes(kind)
        ? route('app.purchasing.documents.show', { kind, document: id })
        : null;
}

/** The purchasing sections as link tabs, with counts. Statements last. */
export function PurchasingTabs({ current, counts }: { current: PurchasingKind | 'statements'; counts?: Partial<Record<PurchasingKind, number>> }) {
    const kinds = Object.keys(KIND_LABELS) as PurchasingKind[];

    return (
        <PageTabs
            label="Purchasing sections"
            tabs={[
                ...kinds.map((kind) => ({
                    label: KIND_LABELS[kind],
                    href: route('app.purchasing.index', kind),
                    active: current === kind,
                    count: counts?.[kind],
                })),
                { label: 'Statements', href: route('app.purchasing.statements.index'), active: current === 'statements' },
            ]}
        />
    );
}
