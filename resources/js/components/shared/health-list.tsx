import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ChevronRight, type LucideIcon } from 'lucide-react';

export type HealthState = 'healthy' | 'degraded' | 'down' | 'unknown';

export interface HealthItem {
    name: string;
    icon: LucideIcon;
    state: HealthState;
    /** Replaces the default state text ("Healthy", "No data yet"…). */
    detail?: string;
    href?: string;
}

const stateText: Record<HealthState, string> = { healthy: 'Healthy', degraded: 'Degraded', down: 'Down', unknown: 'No data yet' };
const stateDot: Record<HealthState, string> = {
    healthy: 'bg-success',
    degraded: 'bg-warning',
    down: 'bg-danger',
    unknown: 'bg-muted-foreground/40',
};

function summary(items: HealthItem[]): { text: string; className: string } {
    if (items.length === 0 || items.every((item) => item.state === 'unknown')) {
        return { text: 'Not reporting yet', className: 'bg-muted text-muted-foreground' };
    }
    if (items.some((item) => item.state === 'down')) {
        return { text: 'Outage', className: 'bg-danger-soft text-danger-foreground' };
    }
    if (items.some((item) => item.state === 'degraded')) {
        return { text: 'Some systems need a look', className: 'bg-warning-soft text-warning-foreground' };
    }
    if (items.some((item) => item.state === 'unknown')) {
        return { text: 'Monitored systems healthy', className: 'bg-success-soft text-success-foreground' };
    }

    return { text: 'All systems operational', className: 'bg-success-soft text-success-foreground' };
}

/** "System health" card: one row per system (icon, name, dot + state, chevron) and a summary pill. */
export function HealthList({ items, title = 'System health', className }: { items: HealthItem[]; title?: string; className?: string }) {
    const pill = summary(items);

    return (
        <Card className={cn('flex flex-col overflow-clip', className)}>
            <div className="flex flex-wrap items-center justify-between gap-2 px-5 pt-5 pb-3">
                <h2 className="text-foreground text-base font-semibold tracking-tight">{title}</h2>
                <span className={cn('rounded-full px-2.5 py-0.5 text-xs font-medium', pill.className)}>{pill.text}</span>
            </div>
            <ul className="divide-y border-t">
                {items.map((item) => {
                    const body = (
                        <>
                            <item.icon className="text-muted-foreground size-4 shrink-0" aria-hidden />
                            <span className="text-foreground flex-1 truncate text-sm">{item.name}</span>
                            <span className="text-muted-foreground flex items-center gap-1.5 text-xs">
                                <span className={cn('size-2 rounded-full', stateDot[item.state])} aria-hidden />
                                {item.detail ?? stateText[item.state]}
                            </span>
                            {item.href && <ChevronRight className="text-muted-foreground size-4" aria-hidden />}
                        </>
                    );

                    return (
                        <li key={item.name}>
                            {item.href ? (
                                <Link href={item.href} className="hover:bg-muted/50 flex items-center gap-3 px-5 py-3 transition-colors">
                                    {body}
                                </Link>
                            ) : (
                                <div className="flex items-center gap-3 px-5 py-3">{body}</div>
                            )}
                        </li>
                    );
                })}
            </ul>
        </Card>
    );
}

export default HealthList;
