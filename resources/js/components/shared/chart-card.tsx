import { toneCircle, toneVar, type ChartTone } from '@/components/shared/trend-chart';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { ArrowDown, ArrowUp, type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

export interface SegmentOption<T extends string> {
    value: T;
    label: string;
}

/** Segmented range control (12W / 6M / 1Y): the active segment is filled primary. */
export function SegmentedControl<T extends string>({
    options,
    value,
    onChange,
    label,
    className,
}: {
    options: SegmentOption<T>[];
    value: T;
    onChange: (value: T) => void;
    /** Accessible name, e.g. "Range". */
    label: string;
    className?: string;
}) {
    return (
        <div role="radiogroup" aria-label={label} className={cn('bg-muted inline-flex items-center gap-0.5 rounded-lg p-0.5', className)}>
            {options.map((option) => {
                const active = option.value === value;

                return (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        onClick={() => onChange(option.value)}
                        className={cn(
                            'focus-visible:ring-ring/35 h-7 min-w-12 rounded-md px-3 text-xs font-semibold transition-colors outline-none focus-visible:ring-[3px]',
                            active ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {option.label}
                    </button>
                );
            })}
        </div>
    );
}

/** Stat pill for a chart's headline change ("↑ 24% vs previous 12 weeks"). */
export function StatPill({
    value,
    label,
    direction = 'up',
    good = true,
}: {
    value: string;
    label?: string;
    direction?: 'up' | 'down';
    good?: boolean;
}) {
    const Icon = direction === 'up' ? ArrowUp : ArrowDown;

    return (
        <div className={cn('rounded-lg px-3 py-2', good ? 'bg-success-soft' : 'bg-danger-soft')}>
            <p className={cn('flex items-center gap-1 text-lg font-bold tabular-nums', good ? 'text-success-foreground' : 'text-danger-foreground')}>
                <Icon className="size-4" strokeWidth={2.5} aria-hidden />
                {value}
            </p>
            {label && <p className="text-muted-foreground text-[11px]">{label}</p>}
        </div>
    );
}

export interface ChartLegendItem {
    label: ReactNode;
    /** Series colour; `muted` for a compare series. */
    tone?: ChartTone | 'muted';
    /** solid dot (default), dashed line (compare), hollow dot (today so far), bar, pale dashed bar (this hour so far). */
    marker?: 'dot' | 'dashed' | 'hollow' | 'bar' | 'bar-partial';
}

/**
 * The one chart legend: small markers + labels under a chart (inside a ChartCard `footer`). Use the same tone as the
 * series; compare series are `muted` + `dashed`, a partial point is `hollow` (area) or `bar-partial` (bars).
 */
export function ChartLegend({ items, className }: { items: ChartLegendItem[]; className?: string }) {
    return (
        <ul className={cn('text-muted-foreground flex flex-wrap items-center gap-x-4 gap-y-1 text-xs', className)}>
            {items.map((item, index) => {
                const colour = item.tone === 'muted' ? 'var(--muted-foreground)' : toneVar[item.tone ?? 'primary'];
                const marker = item.marker ?? 'dot';

                return (
                    <li key={index} className="inline-flex items-center gap-2">
                        {marker === 'dot' && <span className="size-2.5 rounded-full" style={{ background: colour }} aria-hidden />}
                        {marker === 'hollow' && <span className="size-2.5 rounded-full border-2" style={{ borderColor: colour }} aria-hidden />}
                        {marker === 'dashed' && (
                            <span className="inline-block w-5 border-t-2 border-dashed" style={{ borderColor: colour }} aria-hidden />
                        )}
                        {marker === 'bar' && <span className="h-2.5 w-3 rounded-sm" style={{ background: colour }} aria-hidden />}
                        {marker === 'bar-partial' && (
                            <span
                                className="h-2.5 w-3 rounded-sm border border-dashed"
                                style={{ borderColor: colour, background: `color-mix(in oklab, ${colour} 35%, transparent)` }}
                                aria-hidden
                            />
                        )}
                        {item.label}
                    </li>
                );
            })}
        </ul>
    );
}

interface ChartCardProps {
    title: ReactNode;
    subtitle?: ReactNode;
    icon?: LucideIcon;
    tone?: ChartTone;
    /** Right of the title: usually a SegmentedControl. */
    controls?: ReactNode;
    /** Beside the chart on wide screens (a StatPill). */
    stat?: ReactNode;
    footer?: ReactNode;
    className?: string;
    children: ReactNode;
}

/** v2 chart card: icon + title + subtitle, range control on the right, the chart body, optional stat pill. */
export function ChartCard({ title, subtitle, icon: Icon, tone = 'primary', controls, stat, footer, className, children }: ChartCardProps) {
    return (
        <Card className={cn('flex min-w-0 flex-col gap-4 p-5', className)}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    {Icon && (
                        <span className={cn('flex size-10 shrink-0 items-center justify-center rounded-full', toneCircle[tone])}>
                            <Icon className="size-5" aria-hidden />
                        </span>
                    )}
                    <div className="min-w-0">
                        <h2 className="text-foreground text-base leading-6 font-semibold tracking-tight">{title}</h2>
                        {subtitle && <p className="text-muted-foreground text-[13px]">{subtitle}</p>}
                    </div>
                </div>
                {controls}
            </div>
            <div className="flex min-w-0 flex-col gap-4 lg:flex-row">
                <div className="min-w-0 flex-1">{children}</div>
                {stat && <div className="shrink-0 lg:w-36">{stat}</div>}
            </div>
            {footer && <div className="text-muted-foreground border-t pt-3 text-xs">{footer}</div>}
        </Card>
    );
}

export default ChartCard;
