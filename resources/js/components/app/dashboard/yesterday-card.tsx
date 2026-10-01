import { SectionCard } from '@/components/shared/section-card';
import { money, number } from '@/components/shared/trading/format';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import {
    AlertTriangle,
    ArrowDownRight,
    ArrowUpRight,
    Banknote,
    ClipboardCheck,
    PackageX,
    ReceiptText,
    Sparkles,
    Tag,
    Undo2,
    WifiOff,
    type LucideIcon,
} from 'lucide-react';

/** One shop row (or the total) of `SummaryView`. Money as decimal strings; changes as percentages ("12.5"). */
export interface GlanceRow {
    id: string;
    name: string;
    sales: string;
    transactions: number;
    lastWeek: string | null;
    lastWeekChange: string | null;
    lastYear: string | null;
    lastYearChange: string | null;
}

export interface GlanceMover {
    name: string;
    sales: string;
    before: string;
    difference: string;
}

/** `YesterdayAtAGlance::for()` (module 6.3): the morning summary of yesterday, figures computed on the server. */
export interface YesterdayGlance {
    day: string;
    dayLabel: string;
    scope: string;
    total: Omit<GlanceRow, 'id' | 'name'> & { average: string | null };
    shops: GlanceRow[];
    moversUp: GlanceMover[];
    moversDown: GlanceMover[];
    watch: { kind: string; text: string }[];
    narrative: string | null;
}

const watchIcons: Record<string, LucideIcon> = {
    noSales: WifiOff,
    refunds: Undo2,
    voids: ReceiptText,
    discounts: Tag,
    stock: PackageX,
    tills: WifiOff,
    cash: Banknote,
    compliance: ClipboardCheck,
};

/** "+12.5%" in green, "-3%" in red, or a muted note when that day had no sales. */
function Change({ change, before, label }: { change: string | null; before: string | null; label: string }) {
    if (before === null || change === null) {
        return (
            <span className="text-muted-foreground text-sm">
                {label}: {before === null ? 'no sales that day' : 'n/a'}
            </span>
        );
    }
    const down = change.startsWith('-');
    const Icon = down ? ArrowDownRight : ArrowUpRight;

    return (
        <span className="inline-flex items-center gap-1 text-sm">
            <span className={cn('inline-flex items-center gap-0.5 font-medium tabular-nums', down ? 'text-danger' : 'text-success')}>
                <Icon className="size-3.5" aria-hidden />
                {down ? '' : '+'}
                {change}%
            </span>
            <span className="text-muted-foreground">
                {label} ({money(before)})
            </span>
        </span>
    );
}

function percent(change: string | null): string {
    return change === null ? '—' : `${change.startsWith('-') ? '' : '+'}${change}%`;
}

function MoverList({ title, movers, up }: { title: string; movers: GlanceMover[]; up: boolean }) {
    if (movers.length === 0) {
        return null;
    }

    return (
        <div className="space-y-2">
            <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{title}</p>
            <ul className="space-y-1.5">
                {movers.map((mover) => (
                    <li key={mover.name} className="flex items-baseline justify-between gap-3 text-sm">
                        <span className="min-w-0 truncate">{mover.name}</span>
                        <span className="shrink-0 tabular-nums">
                            {money(mover.sales)}{' '}
                            <span className={cn('font-medium', up ? 'text-success' : 'text-danger')}>
                                ({up ? '+' : '-'}
                                {money(mover.difference.replace('-', ''))})
                            </span>
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * "Yesterday at a glance" on the dashboard (module 6.3): yesterday's sales against the same weekday last week and last
 * year, per shop, top movers and what is worth a look, with the AI paragraph of the 07:00 email when there is one.
 */
export function YesterdayCard({ data }: { data: YesterdayGlance }) {
    const { total, shops } = data;
    const hasMovers = data.moversUp.length > 0 || data.moversDown.length > 0;

    return (
        <SectionCard title="Yesterday at a glance" description={`${data.dayLabel} · ${data.scope}`}>
            <div className="space-y-6">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="space-y-1">
                        <p className="text-muted-foreground text-sm">Sales (inc VAT)</p>
                        <p className="text-2xl font-semibold tracking-tight tabular-nums">{money(total.sales)}</p>
                        <p className="text-muted-foreground text-sm">
                            {number(total.transactions)} {total.transactions === 1 ? 'sale' : 'sales'}
                            {total.average !== null && `, average basket ${money(total.average)}`}
                        </p>
                    </div>
                    <div className="flex flex-col gap-1.5 lg:items-end">
                        <Change change={total.lastWeekChange} before={total.lastWeek} label="vs same day last week" />
                        <Change change={total.lastYearChange} before={total.lastYear} label="vs same day last year" />
                    </div>
                </div>

                {data.narrative && (
                    <div className="bg-info-soft flex gap-3 rounded-lg p-4">
                        <Sparkles className="text-info-foreground mt-0.5 size-4 shrink-0" aria-hidden />
                        <div className="space-y-1">
                            <p className="text-sm leading-6">{data.narrative}</p>
                            <p className="text-muted-foreground text-xs">Written by AI for your 7am email, from these figures.</p>
                        </div>
                    </div>
                )}

                {shops.length > 1 && (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-subtle text-muted-foreground text-left text-xs">
                                <tr>
                                    <th className="px-3 py-2 font-medium">Shop</th>
                                    <th className="px-3 py-2 text-right font-medium">Sales</th>
                                    <th className="px-3 py-2 text-right font-medium">vs last week</th>
                                    <th className="hidden px-3 py-2 text-right font-medium sm:table-cell">vs last year</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {shops.map((shop) => (
                                    <tr key={shop.id}>
                                        <td className="px-3 py-2">{shop.name}</td>
                                        <td className="px-3 py-2 text-right tabular-nums">{money(shop.sales)}</td>
                                        <td
                                            className={cn(
                                                'px-3 py-2 text-right tabular-nums',
                                                shop.lastWeekChange?.startsWith('-') ? 'text-danger' : shop.lastWeekChange && 'text-success',
                                            )}
                                        >
                                            {percent(shop.lastWeekChange)}
                                        </td>
                                        <td className="text-muted-foreground hidden px-3 py-2 text-right tabular-nums sm:table-cell">
                                            {percent(shop.lastYearChange)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {(hasMovers || data.watch.length > 0) && (
                    <div className="grid gap-6 md:grid-cols-2">
                        {hasMovers && (
                            <div className="space-y-4">
                                <MoverList title="Up on last week" movers={data.moversUp} up />
                                <MoverList title="Down on last week" movers={data.moversDown} up={false} />
                            </div>
                        )}
                        {data.watch.length > 0 && (
                            <div className="space-y-2">
                                <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">Worth a look</p>
                                <ul className="space-y-2">
                                    {data.watch.map((item) => {
                                        const Icon = watchIcons[item.kind] ?? AlertTriangle;

                                        return (
                                            <li key={item.text} className="flex gap-2.5 text-sm">
                                                <Icon className="text-warning-foreground mt-0.5 size-4 shrink-0" aria-hidden />
                                                <span>{item.text}</span>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </SectionCard>
    );
}

export function YesterdaySkeleton() {
    return (
        <SectionCard title="Yesterday at a glance">
            <div className="space-y-3" aria-busy>
                <Skeleton className="h-8 w-40" />
                <Skeleton className="h-4 w-64" />
                <Skeleton className="h-16 w-full" />
            </div>
        </SectionCard>
    );
}
