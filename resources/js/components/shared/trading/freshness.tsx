import { type FreshnessInfo, type ShopFreshness } from '@/components/shared/trading/types';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { relativeTime } from '@/lib/relative-time';
import { cn } from '@/lib/utils';
import { RefreshCw } from 'lucide-react';

const minutesSince = (iso: string | null) => (iso === null ? null : (Date.now() - new Date(iso).getTime()) / 60000);

const shopDot: Record<ShopFreshness['state'], string> = {
    live: 'bg-success',
    recent: 'bg-warning',
    stale: 'bg-danger',
    never: 'bg-muted-foreground/50',
};

interface FreshnessProps {
    info: FreshnessInfo | undefined;
    /** When the figures were read (the page is cached for a minute). */
    generatedAt?: string;
    loading: boolean;
}

/**
 * "Updated 2 min ago" (DASHBOARD.md §1.8): when the newest till push arrived, green within 2 minutes, amber to 15,
 * grey after (a closed shop sends nothing). The tooltip adds each shop's last push (red after 15 minutes without
 * contact: its recent figures may be incomplete), when the figures were rebuilt and any shop-days still waiting.
 */
export function Freshness({ info, generatedAt, loading }: FreshnessProps) {
    if (!info) {
        return null;
    }
    const { lastPushAt, rebuiltAt, pendingDays, shops } = info;
    const latest = lastPushAt ?? rebuiltAt;
    const age = minutesSince(latest);
    const dot = age === null ? 'bg-muted-foreground/50' : age <= 2 ? 'bg-success' : age <= 15 ? 'bg-warning' : 'bg-muted-foreground/50';
    const stale = (shops ?? []).filter((s) => s.state === 'stale');

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span className="bg-card text-muted-foreground inline-flex h-9 items-center gap-2 rounded-lg border px-3 text-xs tabular-nums" tabIndex={0}>
                    {loading ? <RefreshCw className="size-3.5 animate-spin" aria-hidden /> : <span className={cn('size-2 rounded-full', dot)} aria-hidden />}
                    {loading ? 'Refreshing…' : latest ? `Updated ${relativeTime(latest)}` : 'No till data yet'}
                    {pendingDays > 0 && !loading && <span className="text-warning-foreground">· {pendingDays} refreshing</span>}
                </span>
            </TooltipTrigger>
            <TooltipContent className="grid max-w-72 gap-1.5 text-xs">
                {shops && shops.length > 0 ? (
                    <ul className="grid gap-1">
                        {shops.map((shop) => (
                            <li key={shop.id} className="flex items-center gap-2">
                                <span className={cn('size-2 shrink-0 rounded-full', shopDot[shop.state])} aria-hidden />
                                <span className="min-w-0 flex-1 truncate">{shop.name}</span>
                                <span className="tabular-nums opacity-80">{shop.lastPushAt ? relativeTime(shop.lastPushAt) : 'no push yet'}</span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p>Latest till push: {lastPushAt ? relativeTime(lastPushAt) : 'none yet'}.</p>
                )}
                {stale.length > 0 && (
                    <p>
                        {stale.map((s) => s.name).join(', ')} {stale.length === 1 ? 'has' : 'have'} not synced for over 15 minutes: recent figures may be
                        incomplete until {stale.length === 1 ? 'it catches' : 'they catch'} up.
                    </p>
                )}
                <p>
                    Figures rebuilt {rebuiltAt ? relativeTime(rebuiltAt) : 'not yet'}
                    {generatedAt ? `; read ${relativeTime(generatedAt)} (kept for a minute)` : ''}.
                </p>
                {pendingDays > 0 && <p>{pendingDays} shop-days are waiting to be rebuilt and may still change.</p>}
            </TooltipContent>
        </Tooltip>
    );
}
