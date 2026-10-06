import { type DashboardFigure, type DashboardStatuses } from '@/components/admin/dashboard/types';
import { type StatDelta } from '@/components/shared/stat-card';
import { Card } from '@/components/ui/card';
import { formatMoney, formatNumber } from '@/lib/country';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowRight, ArrowUp, Building2, Lock, Minus } from 'lucide-react';
import { type ReactNode } from 'react';

function DeltaLine({ delta }: { delta: StatDelta }) {
    const good = delta.direction !== 'flat' && delta.direction === (delta.goodWhen ?? 'up');
    const Icon = delta.direction === 'up' ? ArrowUp : delta.direction === 'down' ? ArrowDown : Minus;

    return (
        <span
            className={cn(
                'inline-flex items-center gap-0.5 font-semibold',
                delta.direction === 'flat' ? 'text-muted-foreground' : good ? 'text-success' : 'text-danger',
            )}
        >
            <Icon className="size-3" strokeWidth={2.5} aria-hidden />
            {delta.value}
        </span>
    );
}

function Column({ label, marker, value, sub }: { label: string; marker: ReactNode; value: number; sub: ReactNode }) {
    return (
        <div className="min-w-0 px-4 py-3.5">
            <p className="text-muted-foreground flex items-center gap-1.5 truncate text-xs font-medium">
                {marker}
                {label}
            </p>
            <p className="text-foreground mt-1.5 text-xl font-bold tracking-[-0.02em] tabular-nums">{formatNumber(value)}</p>
            <p className="text-muted-foreground mt-0.5 text-[11px] leading-4 tabular-nums">{sub}</p>
        </div>
    );
}

const dot = (className: string) => <span className={cn('size-2 shrink-0 rounded-full', className)} aria-hidden />;
const share = (part: number, total: number) => `${total > 0 ? Math.round((part / total) * 100) : 0}% of tenants`;

/**
 * "Business overview" (reference-light-final.webp): total tenants with the change since the start of the month,
 * then how many are active, on trial and suspended (share of the total), and paid revenue over 12 weeks when the
 * admin may see money. All from real counts.
 */
export function BusinessOverviewCard({
    statuses,
    tenants,
    revenue,
    viewAllHref,
}: {
    statuses: DashboardStatuses;
    tenants: DashboardFigure;
    revenue: DashboardFigure;
    viewAllHref?: string;
}) {
    const others = [
        statuses.overdue > 0 ? `${statuses.overdue} overdue` : null,
        statuses.cancelled > 0 ? `${statuses.cancelled} cancelled` : null,
    ].filter(Boolean);

    return (
        <Card className="flex min-w-0 flex-col overflow-clip">
            <div className="flex items-center gap-3 px-5 pt-5 pb-3">
                <Building2 className="text-primary size-5" aria-hidden />
                <h2 className="text-foreground flex-1 text-base font-semibold tracking-tight">Business overview</h2>
                {viewAllHref && (
                    <Link href={viewAllHref} className="text-primary inline-flex items-center gap-1 text-sm font-medium hover:underline">
                        View all
                        <ArrowRight className="size-4" aria-hidden />
                    </Link>
                )}
            </div>
            <div className="grid grid-cols-2 border-t sm:grid-cols-4 [&>*:nth-child(even)]:border-l sm:[&>*:nth-child(n+2)]:border-l [&>*:nth-child(n+3)]:border-t sm:[&>*:nth-child(n+3)]:border-t-0">
                <Column
                    label="Tenants"
                    marker={<Building2 className="size-3.5 shrink-0" aria-hidden />}
                    value={statuses.total}
                    sub={
                        tenants.delta ? (
                            <span className="inline-flex flex-wrap items-center gap-x-1">
                                <DeltaLine delta={tenants.delta} />
                                <span>{tenants.delta.label}</span>
                            </span>
                        ) : (
                            'All businesses'
                        )
                    }
                />
                <Column label="Active" marker={dot('bg-success')} value={statuses.active} sub={share(statuses.active, statuses.total)} />
                <Column label="On trial" marker={dot('bg-violet')} value={statuses.trial} sub={share(statuses.trial, statuses.total)} />
                <Column
                    label="Suspended"
                    marker={dot('bg-muted-foreground')}
                    value={statuses.suspended}
                    sub={share(statuses.suspended, statuses.total)}
                />
            </div>
            <div className="text-muted-foreground bg-subtle flex flex-wrap items-center gap-x-4 gap-y-1 border-t px-5 py-2.5 text-xs">
                <span className="inline-flex items-center gap-1.5">
                    Paid in the last 12 weeks:
                    {revenue.locked ? (
                        <span className="inline-flex items-center gap-1">
                            <Lock className="size-3" aria-hidden />
                            Needs billing access
                        </span>
                    ) : (
                        <>
                            <span className="text-foreground font-semibold tabular-nums">{revenue.value ?? formatMoney(0)}</span>
                            {revenue.delta && <DeltaLine delta={revenue.delta} />}
                        </>
                    )}
                </span>
                {others.length > 0 && <span className="ml-auto">Also {others.join(', ')}</span>}
            </div>
        </Card>
    );
}

export default BusinessOverviewCard;
