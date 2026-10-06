import { DescriptionList, type DescriptionItem } from '@/components/shared/description-list';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { ago, clockSkewText, ProblemPills, shopDateTime, SyncStateBadge, TillStateBadge } from '@/components/till-health/format';
import { type HealthThresholds, type ShopHealth, type TillHealth } from '@/components/till-health/types';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

interface TillHealthCardProps {
    till: TillHealth;
    shop: ShopHealth | null;
    thresholds: HealthThresholds;
    /** Link to the admin Till health list. */
    listHref?: string;
}

function When({ at, fallback = 'Never' }: { at: string | null; fallback?: string }) {
    return at ? <span title={shopDateTime(at)}>{ago(at)}</span> : <span className="text-muted-foreground">{fallback}</span>;
}

/** Module 2.7: one till's health on the admin licence page (state, contact, sync, versions, clock, backlog). */
export function TillHealthCard({ till, shop, thresholds, listHref }: TillHealthCardProps) {
    const items: DescriptionItem[] = [
        { label: 'State', value: <TillStateBadge state={till.state} label={till.stateLabel} /> },
        { label: 'Last seen', value: <When at={till.lastSeenAt} /> },
        { label: 'Licence check', value: <When at={till.lastValidatedAt} fallback="Not yet" /> },
        {
            label: 'Sync',
            value: till.isSyncTill ? (
                <SyncStateBadge state={till.syncState} label={till.syncStateLabel} />
            ) : (
                <span className="text-muted-foreground">Syncs through the main till</span>
            ),
        },
        ...(till.isSyncTill
            ? [
                  { label: 'Last push', value: <When at={till.lastPushAt} /> },
                  { label: 'Last pull', value: <When at={till.lastPullAt} /> },
              ]
            : []),
        ...(till.isSyncTill && shop?.lastError
            ? [
                  {
                      label: 'Last sync error',
                      value: (
                          <span className={cn(shop.syncState === 'failing' && 'text-destructive')}>
                              {shop.lastError.code} · {ago(shop.lastError.at)}
                              {shop.lastError.message && <span className="text-muted-foreground block text-xs">{shop.lastError.message}</span>}
                          </span>
                      ),
                  },
              ]
            : []),
        {
            label: 'App version',
            value: till.appVersion ? (
                <span className="inline-flex items-center gap-1.5">
                    SSPOS {till.appVersion}
                    {till.appOutdated && <StatusPill tone="warning">Below {thresholds.minimumAppVersion}</StatusPill>}
                </span>
            ) : null,
        },
        { label: 'Contract', value: till.contractVersion ? `v${till.contractVersion}` : null },
        {
            label: 'Till clock',
            value: <span className={cn(till.clockSkewed && 'text-destructive font-medium')}>{clockSkewText(till.clockSkewSeconds)}</span>,
        },
        {
            label: 'Waiting rows',
            value:
                till.pendingSyncRows === null ? (
                    <span className="text-muted-foreground">Not reported</span>
                ) : (
                    till.pendingSyncRows.toLocaleString('en-GB')
                ),
        },
        { label: 'Problems', value: <ProblemPills problems={till.problems} /> },
    ];

    return (
        <SectionCard
            title="Till health"
            description={`Worked out now. Alerts are checked every ${thresholds.refreshMinutes} minutes.`}
            actions={
                listHref && (
                    <Link href={listHref} className="text-primary text-sm font-medium hover:underline">
                        All tills
                    </Link>
                )
            }
        >
            <DescriptionList layout="rows" items={items} />
        </SectionCard>
    );
}
