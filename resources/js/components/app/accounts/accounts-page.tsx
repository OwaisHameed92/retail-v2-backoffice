import { FilterSelect } from '@/components/app/setup/fields';
import { type TableParams } from '@/components/shared/data-table';
import { PageHeader } from '@/components/shared/page-header';
import { PageTabs } from '@/components/shared/page-tabs';
import { StatusPill } from '@/components/shared/status-badge';
import { money } from '@/components/shared/trading/format';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { Lock, TriangleAlert } from 'lucide-react';
import { type ReactNode } from 'react';
import { type AccountsFiltersState, type AccountsPageProps, type RefundFixSummary } from './types';

export type AccountsTab = 'chart' | 'journals' | 'trial' | 'pl' | 'bs' | 'expenses' | 'vat' | 'assets' | 'export';

const TABS: { key: AccountsTab; label: string; route: string }[] = [
    { key: 'chart', label: 'Chart of accounts', route: 'app.accounts.index' },
    { key: 'journals', label: 'Journals', route: 'app.accounts.journals.index' },
    { key: 'trial', label: 'Trial balance', route: 'app.accounts.trial-balance' },
    { key: 'pl', label: 'Profit and loss', route: 'app.accounts.profit-and-loss' },
    { key: 'bs', label: 'Balance sheet', route: 'app.accounts.balance-sheet' },
    { key: 'expenses', label: 'Expenses', route: 'app.accounts.expenses' },
    { key: 'vat', label: 'VAT return', route: 'app.accounts.vat' },
    { key: 'assets', label: 'Fixed assets', route: 'app.accounts.fixed-assets' },
    { key: 'export', label: 'Export', route: 'app.accounts.export.index' },
];

/** The filters every tab keeps (dates, shop, the refund fix); tab-only filters are dropped when switching. */
function keep(filters: AccountsFiltersState): Record<string, string> {
    return {
        from: filters.from,
        to: filters.to,
        ...(filters.shopLocked ? {} : { shop: filters.shop ?? 'all' }),
        ...(filters.fix ? { fix: '1' } : {}),
    };
}

function londonToday(): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/London' }).format(new Date());
}

function monthStart(day: string, addMonths = 0): string {
    const d = new Date(`${day.slice(0, 7)}-01T12:00:00Z`);
    d.setUTCMonth(d.getUTCMonth() + addMonths);

    return d.toISOString().slice(0, 10);
}

function monthEnd(day: string): string {
    const d = new Date(`${monthStart(day, 1)}T12:00:00Z`);
    d.setUTCDate(0);

    return d.toISOString().slice(0, 10);
}

function quarterStart(day: string, add = 0): string {
    const m = Number(day.slice(5, 7));

    return monthStart(`${day.slice(0, 4)}-${String(m - ((m - 1) % 3)).padStart(2, '0')}-01`, add * 3);
}

const PRESETS: { value: string; label: string; range: () => { from: string; to: string } }[] = [
    { value: 'month', label: 'This month', range: () => ({ from: monthStart(londonToday()), to: londonToday() }) },
    { value: 'lastMonth', label: 'Last month', range: () => ({ from: monthStart(londonToday(), -1), to: monthEnd(monthStart(londonToday(), -1)) }) },
    { value: 'quarter', label: 'This quarter', range: () => ({ from: quarterStart(londonToday()), to: londonToday() }) },
    {
        value: 'lastQuarter',
        label: 'Last quarter',
        range: () => ({ from: quarterStart(londonToday(), -1), to: monthEnd(monthStart(quarterStart(londonToday()), -1)) }),
    },
    { value: 'year', label: 'This year', range: () => ({ from: `${londonToday().slice(0, 4)}-01-01`, to: londonToday() }) },
    { value: '12', label: 'Last 12 months', range: () => ({ from: monthStart(londonToday(), -11), to: londonToday() }) },
];

interface FiltersProps extends AccountsPageProps {
    update: (params: TableParams) => void;
    /** "As at" pages (balance sheet) show only the end date. */
    asAt?: boolean;
    /** Pages without dates (fixed assets). */
    dates?: boolean;
    children?: ReactNode;
}

/** Dates (presets or custom, London dates of the journal), and the shop (pinned for a one-shop user). */
export function AccountsFilters({ filters, options, update, asAt = false, dates = true, children }: FiltersProps) {
    const preset = PRESETS.find((p) => {
        const r = p.range();
        return r.from === filters.from && r.to === filters.to;
    });
    const reset = { page: undefined };

    return (
        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-1 sm:flex-row sm:flex-wrap sm:items-center">
            {dates && !asAt && (
                <>
                    <Select
                        value={preset?.value ?? 'custom'}
                        onValueChange={(value) => {
                            const p = PRESETS.find((x) => x.value === value);
                            if (p) update({ ...p.range(), ...reset });
                        }}
                    >
                        <SelectTrigger className="h-9 w-full sm:w-40" aria-label="Period">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {PRESETS.map((p) => (
                                <SelectItem key={p.value} value={p.value}>
                                    {p.label}
                                </SelectItem>
                            ))}
                            <SelectItem value="custom">Custom dates</SelectItem>
                        </SelectContent>
                    </Select>
                    <div className="flex items-center gap-1.5">
                        <Input
                            type="date"
                            className="h-9 w-full sm:w-38"
                            aria-label="From date"
                            value={filters.from}
                            max={filters.to}
                            onChange={(e) => e.target.value && update({ from: e.target.value, ...reset })}
                        />
                        <span className="text-muted-foreground text-sm">to</span>
                        <Input
                            type="date"
                            className="h-9 w-full sm:w-38"
                            aria-label="To date"
                            value={filters.to}
                            min={filters.from}
                            onChange={(e) => e.target.value && update({ to: e.target.value, ...reset })}
                        />
                    </div>
                </>
            )}
            {dates && asAt && (
                <div className="flex items-center gap-1.5">
                    <span className="text-muted-foreground text-sm">As at</span>
                    <Input
                        type="date"
                        className="h-9 w-full sm:w-38"
                        aria-label="Balance sheet date"
                        value={filters.to}
                        onChange={(e) =>
                            e.target.value && update({ to: e.target.value, from: e.target.value < filters.from ? e.target.value : filters.from })
                        }
                    />
                </div>
            )}
            {filters.shopLocked ? (
                <StatusPill tone="neutral" className="h-9 gap-1.5 px-3">
                    <Lock className="size-3.5" />
                    {options.shops[0]?.label ?? 'Your shop'}
                </StatusPill>
            ) : (
                options.shops.length > 1 && (
                    <FilterSelect
                        value={filters.shop}
                        onChange={(shop) => update({ shop: shop ?? 'all', ...reset })}
                        all="Every shop"
                        options={options.shops}
                        label="Filter by shop"
                    />
                )
            )}
            {children}
        </div>
    );
}

interface LayoutProps {
    tab: AccountsTab;
    filters: AccountsFiltersState;
    title?: string;
    description: string;
    actions?: ReactNode;
    children: ReactNode;
}

/** The Accounts frame: header, the section tabs, then the page. */
export function AccountsPageLayout({ tab, filters, title, description, actions, children }: LayoutProps) {
    const { abilities } = usePage<SharedData>().props;
    const tabs = TABS.filter((t) => t.key !== 'export' || (abilities ?? []).includes('accounts.export'));

    return (
        <AppLayout>
            <Head title={title ?? 'Accounts'} />
            <PageHeader
                title="Accounts and VAT"
                description={description}
                actions={actions}
                tabs={
                    <PageTabs
                        label="Accounts sections"
                        tabs={tabs.map((t) => ({ label: t.label, href: route(t.route, keep(filters)), active: t.key === tab }))}
                    />
                }
            />
            <div className="grid gap-6">{children}</div>
        </AppLayout>
    );
}

/**
 * Refund entries posted before the till's 0.1.15 fix went the wrong way (sales up instead of down). Says how many
 * are in the dates and switches between "as posted" and "corrected".
 */
export function RefundFixNotice({ summary, fix, update }: { summary: RefundFixSummary; fix: boolean; update?: (params: TableParams) => void }) {
    if (summary.entries === 0 && !fix) {
        return null;
    }

    return (
        <Alert variant="warning">
            <TriangleAlert />
            <AlertTitle>{summary.entries === 1 ? '1 refund was' : `${summary.entries} refunds were`} posted before the refund fix</AlertTitle>
            <AlertDescription>
                <p>
                    Till versions before 0.1.15 journalled a refund like a sale, so sales, VAT and cash went up instead of down (
                    {money(summary.sales)} of sales on the wrong side). The till does not re-post old entries.{' '}
                    {!update
                        ? 'Each one is marked in the list below; the trial balance, profit and loss and balance sheet can show them corrected.'
                        : fix
                          ? 'These figures show them corrected (turned the other way round).'
                          : 'These figures show them as posted.'}
                </p>
                {update && (
                    <Button variant="outline" size="sm" className="mt-2" onClick={() => update({ fix: fix ? undefined : '1', page: undefined })}>
                        {fix ? 'Show as posted' : 'Show corrected figures'}
                    </Button>
                )}
            </AlertDescription>
        </Alert>
    );
}

/** A signed amount: a real minus sign, muted when zero; null → "—". */
export function Amount({ value, strong = false, className }: { value: string | null | undefined; strong?: boolean; className?: string }) {
    if (value === null || value === undefined || value === '') {
        return <span className="text-muted-foreground">—</span>;
    }
    const n = Number(value);

    return (
        <span className={cn('tabular-nums', n === 0 && 'text-muted-foreground', strong && 'font-semibold', className)}>
            {n < 0 ? `−${money(Math.abs(n))}` : money(n)}
        </span>
    );
}

export const TYPE_LABELS: Record<string, string> = {
    asset: 'Asset',
    liability: 'Liability',
    equity: 'Capital',
    income: 'Income',
    expense: 'Cost',
};
