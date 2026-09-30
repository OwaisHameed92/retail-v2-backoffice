import { cost, formatDateTime, formatDay, money, qty } from '@/components/app/purchasing/format';
import { FilterSelect } from '@/components/app/setup/fields';
import { type TableParams } from '@/components/shared/data-table';
import { PageTabs } from '@/components/shared/page-tabs';
import { StatusBadge, StatusPill, type StatusTone, type StatusToneMap } from '@/components/shared/status-badge';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { Lock } from 'lucide-react';
import { type RelayState, type TransferFilters, type TransferShared, type TransferStatus } from './types';

export { cost, formatDateTime, formatDay, money, qty };

export const STATUS_LABELS: Record<TransferStatus, string> = {
    requested: 'Requested',
    dispatched: 'Dispatched',
    inTransit: 'In transit',
    received: 'Received',
    partlyReceived: 'Partly received',
    cancelled: 'Cancelled',
};

const STATUS_TONES: StatusToneMap = {
    requested: 'neutral',
    dispatched: 'info',
    inTransit: 'violet',
    received: 'success',
    partlyReceived: 'warning',
    cancelled: 'neutral',
};

export function TransferStatusBadge({ status }: { status: TransferStatus }) {
    return <StatusBadge status={status} tones={STATUS_TONES} label={STATUS_LABELS[status]} />;
}

export const RELAY_LABELS: Record<RelayState, string> = {
    notRelayed: 'Not sent to tills',
    waiting: 'Not pulled yet',
    sent: 'Pulled',
    stored: 'On the till',
    received: 'Received',
};

const RELAY_TONES: Record<RelayState, StatusTone> = {
    notRelayed: 'neutral',
    waiting: 'warning',
    sent: 'info',
    stored: 'success',
    received: 'success',
};

/** Whether the other shop's till has the row yet (contract §10.2 relay). */
export function RelayPill({ state, className }: { state: RelayState; className?: string }) {
    return (
        <StatusPill tone={RELAY_TONES[state]} className={className}>
            {RELAY_LABELS[state]}
        </StatusPill>
    );
}

/** "+2", "−3.5" or "0": a signed quantity difference. */
export function signedQty(value: string | null): string {
    if (value === null) {
        return '—';
    }
    const n = Number(value);

    return n > 0 ? `+${qty(n)}` : n < 0 ? `−${qty(-n)}` : '0';
}

/** "+£1.20" or "−£3.40": a signed money difference. */
export function signedMoney(value: string | null): string {
    if (value === null) {
        return '—';
    }
    const n = Number(value);

    return n > 0 ? `+${money(value)}` : n < 0 ? `−${money(String(-n))}` : money('0');
}

/** Red when short (negative), amber when over (positive), muted when nothing is different. */
export function varianceClass(value: string | null): string {
    const n = Number(value ?? 0);

    return cn('tabular-nums', n < 0 ? 'text-danger-foreground font-medium' : n > 0 ? 'text-warning-foreground font-medium' : 'text-muted-foreground');
}

/** The two transfer screens as link tabs. */
export function TransferTabs({ current, query = {} }: { current: 'list' | 'discrepancies'; query?: Record<string, string> }) {
    return (
        <PageTabs
            label="Transfer sections"
            tabs={[
                { label: 'Transfers', href: route('app.transfers.index', query), active: current === 'list' },
                { label: 'Discrepancies', href: route('app.transfers.discrepancies', query), active: current === 'discrepancies' },
            ]}
        />
    );
}

const reset = { page: undefined };

/** Shop (locked for a one-shop user), sent or received, status and raised / received days. */
export function TransferFilterBar({
    filters,
    shared,
    update,
    status = true,
}: {
    filters: TransferFilters;
    shared: TransferShared;
    update: (params: TableParams) => void;
    status?: boolean;
}) {
    const { shops, oneShop, statuses } = shared;

    return (
        <div className="flex w-full flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
            {oneShop ? (
                <StatusPill tone="neutral" className="h-9 gap-1.5 px-3">
                    <Lock className="size-3.5" />
                    {shops[0]?.name ?? 'Your shop'}
                </StatusPill>
            ) : (
                shops.length > 1 && (
                    <FilterSelect
                        value={filters.shop}
                        onChange={(shop) => update({ shop, flow: shop ? (filters.flow ?? undefined) : undefined, ...reset })}
                        all="Every shop"
                        options={shops.map((s) => ({ value: s.id, label: s.name }))}
                        label="Filter by shop"
                    />
                )
            )}
            {filters.shop && (
                <FilterSelect
                    value={filters.flow}
                    onChange={(flow) => update({ flow, ...reset })}
                    all="Sent and received"
                    options={[
                        { value: 'out', label: 'Sent by this shop' },
                        { value: 'in', label: 'Sent to this shop' },
                    ]}
                    label="Filter by direction"
                />
            )}
            {status && (
                <FilterSelect
                    value={filters.status}
                    onChange={(value) => update({ status: value, ...reset })}
                    all="Any status"
                    options={statuses.map((s) => ({ value: s, label: STATUS_LABELS[s] }))}
                    label="Filter by status"
                    width="sm:w-40"
                />
            )}
            <div className="flex items-center gap-1.5">
                <Input
                    type="date"
                    className="h-9 w-full sm:w-38"
                    aria-label="From day"
                    value={filters.from ?? ''}
                    max={filters.to ?? undefined}
                    onChange={(e) => update({ from: e.target.value || undefined, ...reset })}
                />
                <span className="text-muted-foreground text-sm">to</span>
                <Input
                    type="date"
                    className="h-9 w-full sm:w-38"
                    aria-label="To day"
                    value={filters.to ?? ''}
                    min={filters.from ?? undefined}
                    onChange={(e) => update({ to: e.target.value || undefined, ...reset })}
                />
            </div>
        </div>
    );
}

/** The current filters as query params (for tabs and CSV links). */
export function filterQuery(filters: TransferFilters, extra: Record<string, string | null | undefined> = {}): Record<string, string> {
    const all: Record<string, string | null | undefined> = { ...filters, ...extra };

    return Object.fromEntries(Object.entries(all).filter((entry): entry is [string, string] => typeof entry[1] === 'string' && entry[1] !== ''));
}
