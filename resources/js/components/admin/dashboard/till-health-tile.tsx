import { ago } from '@/components/till-health/format';
import { type TillHealthSummary } from '@/components/till-health/types';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Activity, ChevronRight } from 'lucide-react';

const number = new Intl.NumberFormat('en-GB');

function Figure({ label, value, tone, href }: { label: string; value: number; tone: 'success' | 'warning' | 'danger'; href?: string }) {
    const body = (
        <>
            <span className="text-muted-foreground flex items-center gap-1.5 text-xs">
                <span className={cn('size-2 rounded-full', { success: 'bg-success', warning: 'bg-warning', danger: 'bg-danger' }[tone])} aria-hidden />
                {label}
            </span>
            <span className="text-foreground text-2xl font-semibold tracking-tight tabular-nums">{number.format(value)}</span>
        </>
    );
    const className = 'bg-subtle flex flex-col gap-1 rounded-lg border px-3 py-2.5';

    return href ? (
        <Link href={href} className={cn(className, 'hover:border-border-strong transition-colors')}>
            {body}
        </Link>
    ) : (
        <div className={className}>{body}</div>
    );
}

/** Module 2.7: the admin dashboard's Till health tile (stored health, refreshed every 5 minutes). */
export function TillHealthTile({ summary, href }: { summary: TillHealthSummary; href?: string }) {
    const activated = summary.tills - summary.notActivated;

    return (
        <Card className="flex flex-col gap-3 p-5">
            <div className="flex items-center gap-3">
                <Activity className="text-primary size-5" aria-hidden />
                <h2 className="text-foreground flex-1 text-base font-semibold tracking-tight">Till health</h2>
                {href && (
                    <Link href={href} className="text-primary inline-flex items-center gap-0.5 text-sm font-medium hover:underline">
                        View all
                        <ChevronRight className="size-4" aria-hidden />
                    </Link>
                )}
            </div>
            {activated === 0 ? (
                <p className="text-muted-foreground text-sm">No activated tills yet. Tills show here once their keys are entered.</p>
            ) : (
                <>
                    <div className="grid grid-cols-3 gap-2">
                        <Figure label="Online" value={summary.online} tone="success" />
                        <Figure label="Stale" value={summary.stale} tone="warning" />
                        <Figure label="Offline" value={summary.offline} tone="danger" href={href ? `${href}?filter=offline` : undefined} />
                    </div>
                    <p className="text-muted-foreground text-xs">
                        {summary.attention > 0 ? (
                            <span className="text-danger-foreground font-medium">{number.format(summary.attention)} need attention</span>
                        ) : (
                            'Nothing needs attention'
                        )}
                        {` · ${number.format(summary.shopsSyncing)} of ${number.format(summary.shops)} shops syncing · checked ${ago(summary.checkedAt, 'not yet')}`}
                    </p>
                </>
            )}
        </Card>
    );
}
