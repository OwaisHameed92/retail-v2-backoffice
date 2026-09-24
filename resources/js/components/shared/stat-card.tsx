import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
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

interface StatCardProps {
    label: string;
    value: ReactNode;
    delta?: StatDelta;
    hint?: ReactNode;
    icon?: LucideIcon;
    loading?: boolean;
    className?: string;
}

function deltaTone(delta: StatDelta): string {
    if (delta.direction === 'flat') {
        return 'text-muted-foreground';
    }
    const good = delta.direction === (delta.goodWhen ?? 'up');

    return good ? 'text-success-foreground' : 'text-destructive';
}

export function StatCard({ label, value, delta, hint, icon: Icon, loading = false, className }: StatCardProps) {
    const DeltaIcon = delta?.direction === 'up' ? ArrowUpRight : delta?.direction === 'down' ? ArrowDownRight : Minus;

    return (
        <Card className={cn('flex flex-col gap-2 p-4', className)}>
            <div className="flex items-center justify-between gap-2">
                <p className="text-sm text-muted-foreground">{label}</p>
                {Icon && <Icon className="size-4 text-muted-foreground" aria-hidden />}
            </div>
            {loading ? (
                <Skeleton className="h-8 w-24" />
            ) : (
                <p className="text-2xl font-semibold tracking-tight tabular-nums">{value}</p>
            )}
            {(delta || hint) && !loading && (
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                    {delta && (
                        <span className={cn('inline-flex items-center gap-0.5 font-medium tabular-nums', deltaTone(delta))}>
                            <DeltaIcon className="size-3.5" aria-hidden />
                            <span className="sr-only">{delta.direction === 'up' ? 'Up' : delta.direction === 'down' ? 'Down' : 'No change'}</span>
                            {delta.value}
                        </span>
                    )}
                    {delta?.label && <span className="text-muted-foreground">{delta.label}</span>}
                    {hint && <span className="text-muted-foreground">{hint}</span>}
                </div>
            )}
        </Card>
    );
}

export default StatCard;
