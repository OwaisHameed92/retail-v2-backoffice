import { ago, londonDateTime, ProblemPills, SyncStateBadge, TillStateBadge } from '@/components/till-health/format';
import { type ShopsStatus } from '@/components/till-health/types';
import { EmptyState } from '@/components/shared/empty-state';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { SectionCard } from '@/components/shared/section-card';
import { Badge } from '@/components/ui/badge';
import { Monitor, Star, Store } from 'lucide-react';

/**
 * Module 2.7: "Shops and tills" on the tenant dashboard, read only. Each shop with its state and cloud sync, and
 * each till with when it was last heard from, its app version and anything wrong.
 */
export function ShopsStatusCard({ status }: { status: ShopsStatus }) {
    const t = status.thresholds;

    return (
        <SectionCard
            title="Shops and tills"
            description={`Online means the main till synced in the last ${t.syncOnlineMinutes} minutes, or another till checked its licence in the last ${t.validateOnlineHours} hours.`}
            flush
        >
            {status.shops.length === 0 ? (
                <EmptyState icon={Store} title="No shops yet" body="Your shops and tills appear here once they are set up." size="sm" />
            ) : (
                <ul className="divide-y">
                    {status.shops.map((shop) => (
                        <li key={shop.id} className="px-4 py-4 sm:px-5">
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div className="flex min-w-0 items-center gap-3">
                                    <InitialsAvatar name={shop.name} shape="square" icon={Store} />
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h3 className="truncate text-[15px] font-semibold tracking-tight">{shop.name}</h3>
                                            <Badge variant="neutral" className="font-mono">
                                                {shop.code}
                                            </Badge>
                                        </div>
                                        {shop.health && (
                                            <p className="text-muted-foreground text-[13px]" title={londonDateTime(shop.health.lastContactAt)}>
                                                {shop.health.syncState === 'notLinked'
                                                    ? `Last heard from ${ago(shop.health.lastContactAt, 'never')}`
                                                    : `Last synced ${ago(shop.health.lastSyncAt, 'never')}`}
                                            </p>
                                        )}
                                    </div>
                                </div>
                                {shop.health && (
                                    <div className="flex shrink-0 flex-wrap items-center gap-2">
                                        <TillStateBadge state={shop.health.state} label={shop.health.stateLabel} />
                                        {shop.health.syncState !== 'notLinked' && (
                                            <SyncStateBadge state={shop.health.syncState} label={`Sync: ${shop.health.syncStateLabel.toLowerCase()}`} />
                                        )}
                                    </div>
                                )}
                            </div>

                            {shop.tills.length === 0 ? (
                                <p className="text-muted-foreground mt-3 text-sm">No tills in this shop yet.</p>
                            ) : (
                                <ul className="bg-subtle mt-3 divide-y rounded-lg border">
                                    {shop.tills.map((till) => (
                                        <li key={till.id} className="flex flex-col gap-2 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between">
                                            <div className="flex min-w-0 items-center gap-2.5">
                                                <Monitor className="text-muted-foreground size-4 shrink-0" aria-hidden />
                                                <div className="min-w-0 leading-tight">
                                                    <div className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                                        {till.name}
                                                        <span className="text-muted-foreground font-mono text-xs">{till.code}</span>
                                                        {till.isMainTill && (
                                                            <Badge variant="info">
                                                                <Star aria-hidden />
                                                                Main till
                                                            </Badge>
                                                        )}
                                                    </div>
                                                    {till.health && till.health.state !== 'notActivated' && (
                                                        <div className="text-muted-foreground text-xs" title={londonDateTime(till.health.lastSeenAt)}>
                                                            Seen {ago(till.health.lastSeenAt)}
                                                            {till.health.appVersion && ` · SSPOS ${till.health.appVersion}`}
                                                            {till.health.deviceName && ` · ${till.health.deviceName}`}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex flex-wrap items-center gap-1.5 sm:justify-end">
                                                {till.health ? (
                                                    <>
                                                        <TillStateBadge state={till.health.state} label={till.health.stateLabel} />
                                                        {till.health.problems.filter((p) => p.value !== 'offline').length > 0 && (
                                                            <ProblemPills problems={till.health.problems.filter((p) => p.value !== 'offline')} />
                                                        )}
                                                    </>
                                                ) : (
                                                    <span className="text-muted-foreground text-xs">Not monitored</span>
                                                )}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </SectionCard>
    );
}
