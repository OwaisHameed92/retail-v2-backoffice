import { currentTableParams } from '@/components/shared/data-table';
import { PageTabs } from '@/components/shared/page-tabs';
import { StatusPill, type StatusTone } from '@/components/shared/status-badge';
import { money } from '@/components/shared/trading/format';
import { formatNumber } from '@/lib/country';
import { cn } from '@/lib/utils';
import { type StockStatus, type ValuationBasis } from './types';

export { formatDateTime, formatDay } from '@/components/app/pricing/format';
export { money, number } from '@/components/shared/trading/format';

const qtyFormat = (value: number | string) => formatNumber(value, { maximumFractionDigits: 4 });

/** A stock quantity without trailing zeros: "12.0000" → "12", "−2.5". */
export function qty(value: string | number | null | undefined): string {
    const n = Number(value ?? 0);

    return n < 0 ? `−${qtyFormat(Math.abs(n))}` : qtyFormat(n);
}

/** A movement quantity with its sign: "+24", "−2". */
export function signedQty(value: string): string {
    const n = Number(value);

    return n > 0 ? `+${qty(n)}` : qty(n);
}

/** Money with a proper minus sign. */
export function signedMoney(value: string | null | undefined): string {
    const n = Number(value ?? 0);

    return n < 0 ? `−${money(Math.abs(n))}` : money(n);
}

export function Qty({ value, signed = false, className }: { value: string; signed?: boolean; className?: string }) {
    const n = Number(value);

    return (
        <span className={cn('tabular-nums', n < 0 && 'text-danger-foreground', signed && n > 0 && 'text-success-foreground', className)}>
            {signed ? signedQty(value) : qty(value)}
        </span>
    );
}

const STATUS: Record<StockStatus, { label: string; tone: StatusTone }> = {
    ok: { label: 'In stock', tone: 'success' },
    low: { label: 'Low', tone: 'warning' },
    out: { label: 'Out of stock', tone: 'danger' },
    negative: { label: 'Negative', tone: 'danger' },
};

export function StockStatusPill({ status, className }: { status: StockStatus; className?: string }) {
    return (
        <StatusPill tone={STATUS[status].tone} className={className}>
            {STATUS[status].label}
        </StatusPill>
    );
}

const BASIS: Record<ValuationBasis, { label: string; tone: StatusTone; help: string }> = {
    fifo: { label: 'FIFO', tone: 'success', help: 'Valued from the till’s cost layers.' },
    mixed: { label: 'FIFO + cost', tone: 'info', help: 'Part from cost layers, the rest at the product cost price.' },
    cost: { label: 'Cost price', tone: 'neutral', help: 'No cost layers: valued at the product cost price.' },
    none: { label: 'Not valued', tone: 'warning', help: 'No cost layers and no cost price.' },
};

export function BasisPill({ basis }: { basis: ValuationBasis }) {
    return (
        <span title={BASIS[basis].help}>
            <StatusPill tone={BASIS[basis].tone}>{BASIS[basis].label}</StatusPill>
        </span>
    );
}

export const basisLabel = (basis: ValuationBasis): string => BASIS[basis].label;

/** The current query with changes applied (paging and keyset cursors dropped unless given). */
export function withParams(changes: Record<string, string | number | undefined | null>): Record<string, string | number> {
    const params: Record<string, string | number> = {};

    for (const [key, value] of Object.entries(currentTableParams())) {
        if (value !== undefined && value !== null && typeof value !== 'boolean') {
            params[key] = value;
        }
    }

    delete params.after;
    delete params.before;
    delete params.page;
    for (const [key, value] of Object.entries(changes)) {
        if (value === undefined || value === null || value === '') {
            delete params[key];
        } else {
            params[key] = value;
        }
    }

    return params;
}

type StockTab = 'index' | 'movements' | 'takes' | 'valuation' | 'expiry';

/** The stock section's tabs; the chosen shop goes along. */
export function StockTabs({ active, shop }: { active: StockTab; shop: string | null }) {
    const params = shop ? { shop } : {};
    const tab = (key: StockTab, label: string, name: string) => ({ label, href: route(name, params), active: active === key });

    return (
        <PageTabs
            label="Stock sections"
            tabs={[
                tab('index', 'Stock on hand', 'app.stock.index'),
                tab('movements', 'Movements', 'app.stock.movements'),
                tab('takes', 'Stock takes', 'app.stock.takes.index'),
                tab('valuation', 'Valuation', 'app.stock.valuation'),
                tab('expiry', 'Dates and wastage', 'app.stock.expiry'),
            ]}
        />
    );
}
