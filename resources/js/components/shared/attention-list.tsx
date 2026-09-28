import { EmptyState } from '@/components/shared/empty-state';
import { StatusPill, type StatusTone } from '@/components/shared/status-badge';
import { Card } from '@/components/ui/card';
import { relativeTime } from '@/lib/relative-time';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ChevronRight, CircleCheck, TriangleAlert, type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

export interface AttentionItem {
    id: string;
    /** Short label pill: "Trial", "Alert", "Sync", "License", "Invoice". */
    label: string;
    tone: StatusTone;
    text: ReactNode;
    /** UTC ISO timestamp; shown as "2h ago". */
    at?: string | null;
    href?: string;
}

interface AttentionListProps {
    title?: string;
    icon?: LucideIcon;
    items: AttentionItem[];
    /** Total to show in the count badge when it differs from items.length (e.g. only the first 5 are listed). */
    total?: number;
    /** Shown when there is nothing to do. */
    emptyTitle?: string;
    emptyBody?: string;
    action?: ReactNode;
    className?: string;
}

function Row({ item }: { item: AttentionItem }) {
    const body = (
        <>
            <StatusPill tone={item.tone} className="w-16 justify-center">
                {item.label}
            </StatusPill>
            <span className="text-foreground min-w-0 flex-1 truncate text-sm">{item.text}</span>
            {item.at && <span className="text-muted-foreground shrink-0 text-xs tabular-nums">{relativeTime(item.at)}</span>}
            {item.href && <ChevronRight className="text-muted-foreground size-4 shrink-0" aria-hidden />}
        </>
    );
    const classes = 'flex items-center gap-3 px-5 py-3';

    return (
        <li>
            {item.href ? (
                <Link href={item.href} className={cn(classes, 'hover:bg-muted/50 focus-visible:bg-muted/60 transition-colors outline-none')}>
                    {body}
                </Link>
            ) : (
                <div className={classes}>{body}</div>
            )}
        </li>
    );
}

/** "Needs attention" card: count badge, one row per item (toned label pill, text, relative time, chevron). */
export function AttentionList({
    title = 'Needs attention',
    icon: Icon = TriangleAlert,
    items,
    total,
    emptyTitle = 'Nothing needs attention',
    emptyBody = 'Trials ending, till alerts, sync failures and overdue invoices will show here.',
    action,
    className,
}: AttentionListProps) {
    const count = total ?? items.length;

    return (
        <Card className={cn('flex min-w-0 flex-col overflow-clip', className)}>
            <div className="flex items-center gap-3 px-5 pt-5 pb-3">
                <Icon className="text-danger size-5 shrink-0" aria-hidden />
                <h2 className="text-foreground flex-1 text-base font-semibold tracking-tight">{title}</h2>
                {action}
                {count > 0 && (
                    <span className="bg-danger-soft text-danger-foreground inline-flex h-6 min-w-6 items-center justify-center rounded-full px-2 text-xs font-semibold tabular-nums">
                        {count}
                    </span>
                )}
            </div>
            {items.length > 0 ? (
                <ul className="divide-y border-t">
                    {items.map((item) => (
                        <Row key={item.id} item={item} />
                    ))}
                </ul>
            ) : (
                <EmptyState icon={CircleCheck} title={emptyTitle} body={emptyBody} size="sm" />
            )}
        </Card>
    );
}

export default AttentionList;
