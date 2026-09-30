import { type StatDelta } from '@/components/shared/stat-card';
import { AreaSparkline, toneCircle, type ChartTone } from '@/components/shared/trend-chart';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Minus, type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

interface KpiCardProps {
    label: string;
    icon: LucideIcon;
    /** Tone of the icon circle and sparkline: revenue primary, tills info, trials violet, overdue danger. */
    tone?: ChartTone;
    /** The big number, already formatted. `null` shows "—" with `emptyText`. */
    value: ReactNode | null;
    /** Arrow + % in success/danger, then its label ("vs last month"). */
    delta?: StatDelta;
    /** Values oldest first for the sparkline. Omit or pass fewer than 2 for no chart. */
    series?: number[];
    /** The last sparkline value is still filling up (today so far): drawn dashed, not as a drop. */
    seriesPartial?: boolean;
    /** Footer line, e.g. "£11,540 last month". */
    footer?: ReactNode;
    /** "…" menu slot (a DropdownMenu). */
    menu?: ReactNode;
    /** Shown under "—" when there is no value yet (or a lock hint when hidden). Default "No data yet". */
    emptyText?: ReactNode;
    /** Makes the card a link (the menu stays clickable). */
    href?: string;
    loading?: boolean;
    className?: string;
}

function Delta({ delta }: { delta: StatDelta }) {
    const good = delta.direction !== 'flat' && delta.direction === (delta.goodWhen ?? 'up');
    const Icon = delta.direction === 'up' ? ArrowUp : delta.direction === 'down' ? ArrowDown : Minus;
    const colour = delta.direction === 'flat' ? 'text-muted-foreground' : good ? 'text-success' : 'text-danger';

    return (
        <p className="flex flex-wrap items-center gap-x-1.5 text-[13px]">
            <span className={cn('inline-flex items-center gap-0.5 font-semibold tabular-nums', colour)}>
                <Icon className="size-3.5" strokeWidth={2.5} aria-hidden />
                <span className="sr-only">{delta.direction === 'up' ? 'Up' : delta.direction === 'down' ? 'Down' : 'No change'}</span>
                {delta.value}
            </span>
            {delta.label && <span className="text-muted-foreground">{delta.label}</span>}
        </p>
    );
}

/**
 * v2 KPI card: icon in a soft circle + label + "…" menu; big number (32px bold); delta row; a sparkline with a
 * soft area in the card's tone; footer. With no value it shows "—" and "No data yet" (never a fake number).
 */
export function KpiCard({
    label,
    icon: Icon,
    tone = 'primary',
    value,
    delta,
    series,
    seriesPartial = false,
    footer,
    menu,
    emptyText = 'No data yet',
    href,
    loading = false,
    className,
}: KpiCardProps) {
    const empty = value === null || value === undefined;
    const hasChart = !loading && !empty && series && series.length >= 2;

    return (
        <Card className={cn('relative flex min-w-0 flex-col gap-3 p-5', href && 'hover:border-border-strong transition-colors', className)}>
            <div className="flex items-center gap-3">
                <span className={cn('flex size-10 shrink-0 items-center justify-center rounded-full', toneCircle[tone])}>
                    <Icon className="size-5" aria-hidden />
                </span>
                <p className="text-foreground min-w-0 flex-1 truncate text-sm font-semibold">{label}</p>
                {menu && <div className="relative z-10 -mr-1.5 shrink-0">{menu}</div>}
            </div>

            <div className="min-w-0">
                {loading ? (
                    <Skeleton className="my-1 h-9 w-28" />
                ) : (
                    <p className="text-kpi text-foreground truncate font-bold tracking-[-0.03em] tabular-nums">
                        {empty ? <span className="text-muted-foreground/60">—</span> : value}
                    </p>
                )}
                <div className="mt-1 min-h-5">
                    {loading ? (
                        <Skeleton className="h-4 w-32" />
                    ) : empty ? (
                        <div className="text-muted-foreground text-[13px]">{emptyText}</div>
                    ) : (
                        delta && <Delta delta={delta} />
                    )}
                </div>
            </div>

            <div className="-mx-1 flex h-14 items-end">
                {hasChart ? (
                    <AreaSparkline values={series} tone={tone} partialLast={seriesPartial} />
                ) : (
                    <div className="border-border mx-1 w-full border-b border-dashed" aria-hidden />
                )}
            </div>

            {footer && !loading && <p className="text-muted-foreground text-xs tabular-nums">{footer}</p>}

            {href && (
                <Link
                    href={href}
                    className="focus-visible:ring-ring/40 rounded-card absolute inset-0 outline-none focus-visible:ring-2"
                    aria-label={label}
                />
            )}
        </Card>
    );
}

/** A row of KPI cards: one column on phones, two on tablets, four on wide screens. */
export function KpiGrid({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cn('grid gap-4 sm:grid-cols-2 xl:grid-cols-4', className)}>{children}</div>;
}

export default KpiCard;
