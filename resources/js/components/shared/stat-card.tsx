import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, Minus, type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

export interface StatDelta {
    /** Already formatted, e.g. "12%" or "£1,204". */
    value: string;
    direction: 'up' | 'down' | 'flat';
    /** Which direction is good news. Default "up"; use "down" for refunds, voids, overdue. */
    goodWhen?: 'up' | 'down';
    /** Small text after the delta, e.g. "vs last week". */
    label?: string;
}

export type StatTone = 'primary' | 'success' | 'warning' | 'danger' | 'neutral';

interface StatCardProps {
    label: string;
    value: ReactNode;
    delta?: StatDelta;
    hint?: ReactNode;
    icon?: LucideIcon;
    /** Colour of the icon circle. Default primary (brand blue). */
    tone?: StatTone;
    /** Sparkline or mini chart, shown bottom-right (see Sparkline). */
    chart?: ReactNode;
    /** Makes the whole card a link, e.g. to the filtered list. */
    href?: string;
    loading?: boolean;
    className?: string;
}

const toneCircle: Record<StatTone, string> = {
    primary: 'bg-primary-soft text-primary',
    success: 'bg-success-soft text-success-foreground',
    warning: 'bg-warning-soft text-warning-foreground',
    danger: 'bg-danger-soft text-danger-foreground',
    neutral: 'bg-muted text-muted-foreground',
};

function deltaClasses(delta: StatDelta): string {
    if (delta.direction === 'flat') {
        return 'bg-muted text-muted-foreground';
    }
    const good = delta.direction === (delta.goodWhen ?? 'up');

    return good ? 'bg-success-soft text-success-foreground' : 'bg-danger-soft text-danger-foreground';
}

/**
 * KPI tile: label with an icon in a tinted circle, a large number, a delta pill (green when good, red when
 * bad) and a hint line; optional sparkline. Use four across on desktop, two on phones.
 */
export function StatCard({ label, value, delta, hint, icon: Icon, tone = 'primary', chart, href, loading = false, className }: StatCardProps) {
    const DeltaIcon = delta?.direction === 'up' ? ArrowUpRight : delta?.direction === 'down' ? ArrowDownRight : Minus;

    const body = (
        <>
            <div className="flex items-start justify-between gap-3">
                <p className="text-muted-foreground pt-1 text-[13px] leading-5 font-medium">{label}</p>
                {Icon && (
                    <span className={cn('flex size-8 shrink-0 items-center justify-center rounded-full', toneCircle[tone])}>
                        <Icon className="size-4" aria-hidden />
                    </span>
                )}
            </div>
            <div className="flex items-end justify-between gap-3">
                <div className="min-w-0">
                    {loading ? (
                        <Skeleton className="my-1 h-7 w-24" />
                    ) : (
                        <p className="text-foreground sm:text-stat truncate text-2xl font-semibold tracking-[-0.02em] tabular-nums">{value}</p>
                    )}
                    {(delta || hint) &&
                        (loading ? (
                            <Skeleton className="mt-2 h-4 w-32" />
                        ) : (
                            <div className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                                {delta && (
                                    <span
                                        className={cn(
                                            'inline-flex h-5 items-center gap-0.5 rounded-full px-1.5 font-medium tabular-nums',
                                            deltaClasses(delta),
                                        )}
                                    >
                                        <DeltaIcon className="size-3.5" aria-hidden />
                                        <span className="sr-only">
                                            {delta.direction === 'up' ? 'Up' : delta.direction === 'down' ? 'Down' : 'No change'}
                                        </span>
                                        {delta.value}
                                    </span>
                                )}
                                {delta?.label && <span className="text-muted-foreground">{delta.label}</span>}
                                {hint && <span className="text-muted-foreground">{hint}</span>}
                            </div>
                        ))}
                </div>
                {chart && !loading && <div className="shrink-0">{chart}</div>}
            </div>
        </>
    );

    const classes = cn('flex min-w-0 flex-col gap-3 p-4 sm:p-5', href && 'hover:border-border-strong transition-colors duration-150', className);

    if (href) {
        return (
            <Card className={cn('relative', classes)}>
                {body}
                <Link
                    href={href}
                    className="focus-visible:ring-ring/40 absolute inset-0 rounded-xl outline-none focus-visible:ring-2"
                    aria-label={label}
                />
            </Card>
        );
    }

    return <Card className={classes}>{body}</Card>;
}

/** A row of stat cards: 2 columns on phones and tablets, `columns` on desktop. */
export function StatGrid({ columns = 4, className, children }: { columns?: 2 | 3 | 4; className?: string; children: ReactNode }) {
    return (
        <div className={cn('grid grid-cols-2 gap-3 sm:gap-4', columns === 3 && 'lg:grid-cols-3', columns === 4 && 'xl:grid-cols-4', className)}>
            {children}
        </div>
    );
}

export default StatCard;
