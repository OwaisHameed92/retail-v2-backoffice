import { StatusPill } from '@/components/shared/status-badge';
import { ago } from '@/components/till-health/format';
import { Card } from '@/components/ui/card';
import { formatDateTime } from '@/lib/country';
import { cn } from '@/lib/utils';
import { CalendarClock, CircleAlert, Layers, RefreshCcwDot, type LucideIcon } from 'lucide-react';
import { healthLabels, healthTones } from './format';
import { type HealthItem, type HealthState } from './types';

const icons: Record<HealthItem['key'], LucideIcon> = { scheduler: CalendarClock, queue: Layers, failed: CircleAlert, sync: RefreshCcwDot };

/** Section each tile jumps to. */
const anchors: Partial<Record<HealthItem['key'], string>> = { scheduler: '#scheduled-tasks', failed: '#failed-jobs', sync: '#sync-problems' };

const circle: Record<HealthState, string> = {
    ok: 'bg-success-soft text-success-foreground',
    warning: 'bg-warning-soft text-warning-foreground',
    problem: 'bg-danger-soft text-danger-foreground',
    unknown: 'bg-muted text-muted-foreground',
};

function Tile({ item }: { item: HealthItem }) {
    const Icon = icons[item.key];
    const anchor = anchors[item.key];
    const body = (
        <Card className={cn('flex h-full flex-col gap-3 p-5 transition-shadow', anchor && 'hover:shadow-md')}>
            <div className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    <span className={cn('flex size-9 shrink-0 items-center justify-center rounded-full', circle[item.state])}>
                        <Icon className="size-[18px]" aria-hidden />
                    </span>
                    <span className="text-muted-foreground truncate text-sm font-medium">{item.label}</span>
                </div>
                <StatusPill tone={healthTones[item.state]}>{healthLabels[item.state]}</StatusPill>
            </div>
            <div className="text-foreground text-2xl font-semibold tracking-tight tabular-nums">{item.value}</div>
            <div className="text-muted-foreground mt-auto space-y-0.5 text-sm">
                <p>{item.detail}</p>
                {item.at && item.atLabel && (
                    <p>
                        {item.atLabel}{' '}
                        <time dateTime={item.at} title={formatDateTime(item.at)} className="text-foreground font-medium tabular-nums">
                            {ago(item.at)}
                        </time>
                    </p>
                )}
            </div>
        </Card>
    );

    return anchor ? (
        <a href={anchor} className="focus-visible:ring-ring rounded-card block focus-visible:ring-2 focus-visible:outline-none">
            {body}
        </a>
    ) : (
        body
    );
}

/** Scheduler, queue backlog, failed jobs and sync problems at a glance (green, amber, red). */
export function HealthStrip({ items }: { items: HealthItem[] }) {
    return (
        <section aria-label="Background work health" className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {items.map((item) => (
                <Tile key={item.key} item={item} />
            ))}
        </section>
    );
}
