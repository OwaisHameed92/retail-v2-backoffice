import { ago, shopDateTime, SyncStateBadge, TillStateBadge } from '@/components/till-health/format';
import { type ShopHealth, type TillHealth } from '@/components/till-health/types';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Activity, ChevronRight } from 'lucide-react';

/**
 * Module 2.7: a shop's health under its card on the admin tenant page: state, sync, last push / pull and the last
 * sync error. `href` links to the Till health list filtered to the business.
 */
export function BranchHealthStrip({ health, href }: { health: ShopHealth | null; href?: string }) {
    if (!health) {
        return null;
    }
    const linked = health.syncState !== 'notLinked';

    return (
        <div className="flex flex-col gap-2 border-t px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div className="flex min-w-0 flex-wrap items-center gap-x-4 gap-y-1.5 text-[13px]">
                <span className="inline-flex items-center gap-1.5 font-medium">
                    <Activity className="text-muted-foreground size-3.5" aria-hidden />
                    Health
                </span>
                <TillStateBadge state={health.state} label={health.stateLabel} />
                {linked && <SyncStateBadge state={health.syncState} label={`Sync: ${health.syncStateLabel.toLowerCase()}`} />}
                <span className="text-muted-foreground tabular-nums">
                    {health.tillsOnline} of {health.tills} {health.tills === 1 ? 'till' : 'tills'} online
                    {health.tillsOffline > 0 && ` · ${health.tillsOffline} offline`}
                </span>
                {linked ? (
                    <span className="text-muted-foreground" title={shopDateTime(health.lastSyncAt)}>
                        Push {ago(health.lastPushAt, 'never')} · pull {ago(health.lastPullAt, 'never')}
                    </span>
                ) : (
                    <span className="text-muted-foreground">Last contact {ago(health.lastContactAt, 'never')}</span>
                )}
                {health.lastError && health.syncState === 'failing' && (
                    <span className="text-destructive min-w-0 truncate" title={health.lastError.message ?? undefined}>
                        {health.lastError.code}: {health.lastError.message}
                    </span>
                )}
            </div>
            {href && (
                <Link href={href} className="text-primary inline-flex shrink-0 items-center gap-0.5 text-[13px] font-medium hover:underline">
                    Till health
                    <ChevronRight className="size-3.5" aria-hidden />
                </Link>
            )}
        </div>
    );
}

const dot: Record<TillHealth['state'], string> = {
    online: 'bg-success',
    stale: 'bg-warning',
    offline: 'bg-danger',
    notActivated: 'bg-muted-foreground/50',
};

/** Compact till health for a table cell: dot + state, last seen and app version, problems count. */
export function TillHealthCell({ health }: { health: TillHealth | undefined | null }) {
    if (!health) {
        return <span className="text-muted-foreground text-sm">Not monitored</span>;
    }

    return (
        <div className="leading-tight">
            <div className="flex items-center gap-1.5 text-sm">
                <span className={cn('size-2 shrink-0 rounded-full', dot[health.state])} aria-hidden />
                <span className={cn(health.state === 'offline' && 'text-destructive font-medium')}>{health.stateLabel}</span>
                {health.problems.length > 0 && health.state !== 'offline' && (
                    <span className="text-warning-foreground text-xs font-medium">
                        · {health.problems.map((problem) => problem.label.toLowerCase()).join(', ')}
                    </span>
                )}
            </div>
            {health.state !== 'notActivated' && (
                <div className="text-muted-foreground text-xs" title={shopDateTime(health.lastSeenAt)}>
                    Seen {ago(health.lastSeenAt)}
                    {health.appVersion && ` · v${health.appVersion}`}
                </div>
            )}
        </div>
    );
}
