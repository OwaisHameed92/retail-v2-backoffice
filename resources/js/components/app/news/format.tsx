import { formatDateTime, formatDay, money } from '@/components/app/purchasing/format';
import { PageTabs } from '@/components/shared/page-tabs';
import { StatusBadge, statusLabel, type StatusToneMap } from '@/components/shared/status-badge';
import { type NewsKind } from './types';

export { formatDateTime, formatDay, money };

export const NEWS_TONES: StatusToneMap = {
    active: 'success',
    archived: 'neutral',
    received: 'info',
    partReturned: 'warning',
    settled: 'success',
    awaitingCredit: 'warning',
    credited: 'success',
    unclaimed: 'warning',
    claimed: 'success',
};

const LABELS: Record<string, string> = {
    active: 'On sale',
    partReturned: 'Part returned',
    awaitingCredit: 'Awaiting credit',
    unclaimed: 'To claim',
};

export const FREQUENCIES: Record<string, string> = {
    daily: 'Daily',
    weekly: 'Weekly',
    partWork: 'Part work',
};

export function NewsStatus({ status }: { status: string | null }) {
    return status ? <StatusBadge status={status} tones={NEWS_TONES} label={LABELS[status]} /> : <span className="text-muted-foreground">—</span>;
}

export function statusText(status: string): string {
    return LABELS[status] ?? statusLabel(status);
}

export const KIND_LABELS: Record<NewsKind, string> = {
    titles: 'Titles',
    deliveries: 'Deliveries',
    returns: 'Returns and credits',
    vouchers: 'Vouchers',
};

const count = new Intl.NumberFormat('en-GB');

export function copies(value: number | null | undefined): string {
    return value === null || value === undefined ? '—' : count.format(value);
}

export function percent(value: number | string | null | undefined): string {
    return value === null || value === undefined || value === '' ? '—' : `${Number(value).toLocaleString('en-GB', { maximumFractionDigits: 1 })}%`;
}

/** The newspaper sections as link tabs, with counts. The weekly summary last. */
export function NewsTabs({ current, counts }: { current: NewsKind | 'summary'; counts?: Partial<Record<NewsKind, number>> }) {
    const kinds = Object.keys(KIND_LABELS) as NewsKind[];

    return (
        <PageTabs
            label="Newspaper sections"
            tabs={[
                { label: 'Weekly summary', href: route('app.news.summary'), active: current === 'summary' },
                ...kinds.map((kind) => ({
                    label: KIND_LABELS[kind],
                    href: route('app.news.index', kind),
                    active: current === kind,
                    count: counts?.[kind],
                })),
            ]}
        />
    );
}
