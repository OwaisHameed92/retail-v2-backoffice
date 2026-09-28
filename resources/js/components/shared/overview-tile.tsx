import { type StatDelta } from '@/components/shared/stat-card';
import { toneCircle, type ChartTone } from '@/components/shared/trend-chart';
import { cn } from '@/lib/utils';
import { ArrowDown, ArrowUp, Minus, type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

const tileTint: Record<ChartTone, string> = {
    primary: 'bg-primary-soft/60',
    success: 'bg-success-soft/60',
    info: 'bg-info-soft/60',
    violet: 'bg-violet-soft/60',
    warning: 'bg-warning-soft/60',
    danger: 'bg-danger-soft/60',
};

interface OverviewTileProps {
    label: string;
    icon: LucideIcon;
    tone?: ChartTone;
    /** Formatted number; `null` shows "—" and "No data yet". */
    value: ReactNode | null;
    delta?: StatDelta;
    /** Shown under "—" when there is no value (or a lock hint when hidden). Default "No data yet". */
    emptyText?: ReactNode;
    className?: string;
}

/** Soft tinted tile (Business overview): icon circle, label, number and a small delta. */
export function OverviewTile({ label, icon: Icon, tone = 'primary', value, delta, emptyText = 'No data yet', className }: OverviewTileProps) {
    const empty = value === null || value === undefined;
    const good = delta && delta.direction !== 'flat' && delta.direction === (delta.goodWhen ?? 'up');
    const DeltaIcon = delta?.direction === 'down' ? ArrowDown : delta?.direction === 'flat' ? Minus : ArrowUp;
    const deltaColour = delta?.direction === 'flat' ? 'text-muted-foreground' : good ? 'text-success' : 'text-danger';

    return (
        <div className={cn('flex min-w-0 items-center gap-3 rounded-lg p-4', tileTint[tone], className)}>
            <span className={cn('flex size-11 shrink-0 items-center justify-center rounded-full', toneCircle[tone])}>
                <Icon className="size-5" aria-hidden />
            </span>
            <div className="min-w-0">
                <p className="text-muted-foreground truncate text-xs font-medium">{label}</p>
                <p className="text-foreground truncate text-xl font-bold tracking-[-0.02em] tabular-nums">
                    {empty ? <span className="text-muted-foreground/60">—</span> : value}
                </p>
                {empty ? (
                    <div className="text-muted-foreground text-[11px]">{emptyText}</div>
                ) : (
                    delta && (
                        <p className="flex items-center gap-1 text-[11px]">
                            <span className={cn('inline-flex items-center gap-0.5 font-semibold', deltaColour)}>
                                <DeltaIcon className="size-3" strokeWidth={2.5} aria-hidden />
                                {delta.value}
                            </span>
                            {delta.label && <span className="text-muted-foreground">{delta.label}</span>}
                        </p>
                    )
                )}
            </div>
        </div>
    );
}

export default OverviewTile;
