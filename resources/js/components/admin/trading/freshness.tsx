import { type TradingData } from '@/components/admin/trading/types';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { relativeTime } from '@/lib/relative-time';
import { cn } from '@/lib/utils';
import { RefreshCw } from 'lucide-react';

const minutesSince = (iso: string | null) => (iso === null ? null : (Date.now() - new Date(iso).getTime()) / 60000);

/**
 * "Updated 2m ago" (DASHBOARD.md §1.8): when the newest till data arrived, green within 2 minutes, amber to 15,
 * grey after (a closed shop sends nothing). The tooltip adds when the figures were rebuilt and cached, and any
 * shop-days still waiting to be refreshed.
 */
export function Freshness({ data, loading }: { data: TradingData | undefined; loading: boolean }) {
    if (!data) {
        return null;
    }
    const { lastPushAt, rebuiltAt, pendingDays } = data.activity;
    const latest = lastPushAt ?? rebuiltAt;
    const age = minutesSince(latest);
    const dot = age === null ? 'bg-muted-foreground/50' : age <= 2 ? 'bg-success' : age <= 15 ? 'bg-warning' : 'bg-muted-foreground/50';

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span className="bg-card text-muted-foreground inline-flex h-9 items-center gap-2 rounded-lg border px-3 text-xs tabular-nums" tabIndex={0}>
                    {loading ? <RefreshCw className="size-3.5 animate-spin" aria-hidden /> : <span className={cn('size-2 rounded-full', dot)} aria-hidden />}
                    {loading ? 'Refreshing…' : latest ? `Updated ${relativeTime(latest)}` : 'No till data yet'}
                    {pendingDays > 0 && !loading && <span className="text-warning-foreground">· {pendingDays} refreshing</span>}
                </span>
            </TooltipTrigger>
            <TooltipContent className="max-w-64 text-xs">
                <p>Latest till push: {lastPushAt ? relativeTime(lastPushAt) : 'none yet'}.</p>
                <p>Figures rebuilt: {rebuiltAt ? relativeTime(rebuiltAt) : 'not yet'}; this page is cached for a minute ({relativeTime(data.generatedAt)}).</p>
                {pendingDays > 0 && <p>{pendingDays} shop-days are waiting to be rebuilt and may still change.</p>}
            </TooltipContent>
        </Tooltip>
    );
}
