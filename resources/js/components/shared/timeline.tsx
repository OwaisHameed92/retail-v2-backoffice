import { EmptyState } from '@/components/shared/empty-state';
import { cn } from '@/lib/utils';
import { History, type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

export type TimelineTone = 'neutral' | 'primary' | 'success' | 'warning' | 'danger';

export interface TimelineItem {
    id: string | number;
    icon?: LucideIcon;
    tone?: TimelineTone;
    /** One line: "<strong>Sam</strong> suspended the licence". */
    title: ReactNode;
    /** Already formatted time, e.g. "24 Sept 2026, 09:41". */
    time?: string;
    /** Extra detail under the title: a reason, or a TimelineChanges list. */
    body?: ReactNode;
}

const toneClasses: Record<TimelineTone, string> = {
    neutral: 'bg-card text-muted-foreground ring-border',
    primary: 'bg-primary-soft text-primary ring-primary/15',
    success: 'bg-success-soft text-success-foreground ring-success/20',
    warning: 'bg-warning-soft text-warning-foreground ring-warning/25',
    danger: 'bg-danger-soft text-danger-foreground ring-destructive/20',
};

interface TimelineProps {
    items: TimelineItem[];
    /** Shown when there are no items. */
    emptyTitle?: string;
    emptyBody?: ReactNode;
    className?: string;
}

/** Activity / audit log: icon dots on a vertical rail, newest first. */
export function Timeline({ items, emptyTitle = 'No activity yet', emptyBody, className }: TimelineProps) {
    if (items.length === 0) {
        return <EmptyState icon={History} title={emptyTitle} body={emptyBody} size="sm" />;
    }

    return (
        <ol className={cn('relative grid', className)}>
            {items.map((item, index) => {
                const Icon = item.icon ?? History;
                const last = index === items.length - 1;

                return (
                    <li key={item.id} className="relative flex gap-3.5 pb-6 last:pb-0">
                        {!last && <span className="bg-border absolute top-8 bottom-0 left-[15px] w-px" aria-hidden />}
                        <span
                            className={cn(
                                'relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full ring-1',
                                toneClasses[item.tone ?? 'neutral'],
                            )}
                        >
                            <Icon className="size-3.5" aria-hidden />
                        </span>
                        <div className="min-w-0 flex-1 pt-1">
                            <div className="flex flex-col gap-x-3 gap-y-0.5 sm:flex-row sm:items-baseline sm:justify-between">
                                <p className="text-foreground text-sm leading-6">{item.title}</p>
                                {item.time && <time className="text-muted-foreground shrink-0 text-xs tabular-nums">{item.time}</time>}
                            </div>
                            {item.body && <div className="mt-2 text-sm">{item.body}</div>}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}

export interface TimelineChange {
    label: string;
    from: ReactNode | null;
    to: ReactNode;
}

/** "Field: old → new" rows inside a timeline item. */
export function TimelineChanges({ changes }: { changes: TimelineChange[] }) {
    if (changes.length === 0) {
        return null;
    }

    return (
        <dl className="bg-subtle grid gap-1.5 rounded-lg border px-3 py-2.5 text-[13px]">
            {changes.map((change) => (
                <div key={change.label} className="grid gap-x-3 sm:grid-cols-[10rem_minmax(0,1fr)]">
                    <dt className="text-muted-foreground">{change.label}</dt>
                    <dd className="min-w-0 break-words">
                        {change.from !== null && change.from !== undefined && (
                            <>
                                <span className="text-muted-foreground line-through decoration-1">{change.from}</span>
                                <span className="text-muted-foreground px-1.5" aria-label="changed to">
                                    →
                                </span>
                            </>
                        )}
                        <span className="text-foreground font-medium">{change.to}</span>
                    </dd>
                </div>
            ))}
        </dl>
    );
}

export default Timeline;
