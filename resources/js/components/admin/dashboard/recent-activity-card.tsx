import { type DashboardActivity } from '@/components/admin/dashboard/types';
import { EmptyState } from '@/components/shared/empty-state';
import { toneCircle, type ChartTone } from '@/components/shared/trend-chart';
import { Card } from '@/components/ui/card';
import { relativeTime } from '@/lib/relative-time';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Building2, ChevronRight, Clock, Coins, UserRoundPlus, type LucideIcon } from 'lucide-react';

const kinds: Record<DashboardActivity['kind'], { icon: LucideIcon; tone: ChartTone }> = {
    tenant: { icon: Building2, tone: 'primary' },
    invoice: { icon: Coins, tone: 'info' },
    lead: { icon: UserRoundPlus, tone: 'violet' },
};

/**
 * "Recent activity" on the admin dashboard (reference-light-final.webp): new tenants, paid invoices and new leads,
 * newest first, each with a toned icon circle, a one-line detail, the relative time and a link.
 */
export function RecentActivityCard({ items }: { items: DashboardActivity[] }) {
    return (
        <Card className="flex min-w-0 flex-col overflow-clip">
            <div className="flex items-center gap-3 px-5 pt-5 pb-3">
                <Clock className="text-muted-foreground size-5" aria-hidden />
                <h2 className="text-foreground flex-1 text-base font-semibold tracking-tight">Recent activity</h2>
            </div>
            {items.length === 0 ? (
                <div className="border-t">
                    <EmptyState icon={Clock} title="No activity yet" body="New tenants, paid invoices and new leads will show here." size="sm" />
                </div>
            ) : (
                <ul className="divide-y border-t">
                    {items.map((item) => {
                        const kind = kinds[item.kind];

                        return (
                            <li key={item.id}>
                                <Link
                                    href={item.href}
                                    className="hover:bg-muted/50 focus-visible:bg-muted/60 flex items-center gap-3 px-5 py-3 transition-colors outline-none"
                                >
                                    <span className={cn('flex size-9 shrink-0 items-center justify-center rounded-full', toneCircle[kind.tone])}>
                                        <kind.icon className="size-4" aria-hidden />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="text-foreground block truncate text-sm font-medium">{item.title}</span>
                                        <span className="text-muted-foreground block truncate text-xs">{item.detail}</span>
                                    </span>
                                    <time dateTime={item.at} className="text-muted-foreground shrink-0 text-xs tabular-nums">
                                        {relativeTime(item.at)}
                                    </time>
                                    <ChevronRight className="text-muted-foreground size-4 shrink-0" aria-hidden />
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            )}
        </Card>
    );
}

export default RecentActivityCard;
