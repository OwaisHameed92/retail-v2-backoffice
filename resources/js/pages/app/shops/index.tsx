import { EndsAtText, LicenceKindPill, ShopsTabs } from '@/components/app/shops/licence-bits';
import { RequestDialog } from '@/components/app/shops/request-dialog';
import { RequestsCard } from '@/components/app/shops/requests-card';
import { daysUntil, londonDate, type ShopRow, type ShopsIndexProps } from '@/components/app/shops/types';
import { EmptyState } from '@/components/shared/empty-state';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { StatusBadge, StatusPill } from '@/components/shared/status-badge';
import { ago, londonDateTime, SyncStateBadge, TillStateBadge } from '@/components/till-health/format';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { CalendarClock, ChevronRight, Info, MapPin, Monitor, Plus, Store, Wifi } from 'lucide-react';
import { useState } from 'react';

export default function ShopsIndex({ shops, summary, requests, requestOptions, can, restricted, thresholds }: ShopsIndexProps) {
    const [asking, setAsking] = useState(false);
    const renewalDays = daysUntil(summary.nextEndsAt);

    return (
        <AppLayout>
            <Head title="Shops and tills" />
            <PageHeader
                title="Shops and tills"
                description="Your shops, their tills and licences, and how each till is doing. Switch & Save looks after the licences."
                actions={
                    can.manage && requestOptions.shops.length > 0 ? (
                        <Button onClick={() => setAsking(true)}>
                            <Plus aria-hidden />
                            Ask for more tills
                        </Button>
                    ) : undefined
                }
                tabs={<ShopsTabs active="shops" />}
            />

            <div className="grid gap-6">
                {restricted && (
                    <Alert variant="info">
                        <Info aria-hidden />
                        <AlertDescription>You can see your own shop only. The business details are managed by the owner.</AlertDescription>
                    </Alert>
                )}

                <StatGrid>
                    <StatCard
                        label="Shops"
                        value={summary.shops}
                        hint={restricted ? 'Your shop' : `${summary.shopsAllowed} allowed on your plan`}
                        icon={Store}
                        tone="primary"
                    />
                    <StatCard label="Tills" value={summary.tills} hint={`${summary.tillsAllowed} allowed`} icon={Monitor} tone="neutral" />
                    <StatCard
                        label="Tills online"
                        value={`${summary.tillsOnline} of ${summary.tills}`}
                        hint={`Heard from in the last ${thresholds.validateOnlineHours} hours`}
                        icon={Wifi}
                        tone={summary.tills > 0 && summary.tillsOnline === summary.tills ? 'success' : 'warning'}
                    />
                    <StatCard
                        label="Next licence end"
                        value={summary.nextEndsAt ? londonDate(summary.nextEndsAt) : 'None'}
                        hint={
                            renewalDays === null
                                ? 'No end date yet'
                                : renewalDays < 0
                                  ? 'Already passed: please call us'
                                  : `In ${renewalDays} ${renewalDays === 1 ? 'day' : 'days'}`
                        }
                        icon={CalendarClock}
                        tone={renewalDays !== null && renewalDays <= 14 ? 'warning' : 'neutral'}
                    />
                </StatGrid>

                <SectionCard
                    title={restricted ? 'Your shop' : 'Shops'}
                    description="Open a shop to change its details or see each till's licence and health."
                    flush
                >
                    {shops.length === 0 ? (
                        <EmptyState icon={Store} title="No shops yet" body="Your shops appear here once Switch & Save has set them up." size="sm" />
                    ) : (
                        <ul className="divide-y">
                            {shops.map((shop) => (
                                <ShopItem key={shop.id} shop={shop} />
                            ))}
                        </ul>
                    )}
                </SectionCard>

                <RequestsCard requests={requests} />
            </div>

            <RequestDialog open={asking} onOpenChange={setAsking} options={requestOptions} />
        </AppLayout>
    );
}

function ShopItem({ shop }: { shop: ShopRow }) {
    const health = shop.health;
    const full = shop.tillsActive >= shop.tillsAllowed;

    return (
        <li>
            <Link
                href={route('app.shops.show', shop.id)}
                className="hover:bg-subtle focus-visible:bg-subtle flex flex-col gap-3 px-4 py-4 outline-none sm:px-5 lg:flex-row lg:items-center"
            >
                <div className="flex min-w-0 flex-1 items-start gap-3">
                    <InitialsAvatar name={shop.name} shape="square" icon={Store} />
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="truncate text-[15px] font-semibold tracking-tight">{shop.name}</h3>
                            <Badge variant="neutral" className="font-mono">
                                {shop.code}
                            </Badge>
                            {!shop.isActive && <StatusBadge status="inactive" label="Closed" />}
                        </div>
                        <p className="text-muted-foreground mt-0.5 flex items-start gap-1.5 text-[13px]">
                            <MapPin className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                            <span className="line-clamp-1">{shop.address ?? 'No address yet'}</span>
                        </p>
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-x-6 gap-y-2 pl-12 text-[13px] sm:grid-cols-3 lg:w-[34rem] lg:shrink-0 lg:pl-0">
                    <div className="min-w-0">
                        <p className="text-muted-foreground text-xs">Tills</p>
                        <p className="font-medium tabular-nums">
                            {shop.tillsActive} of {shop.tillsAllowed}
                            {full && <span className="text-muted-foreground font-normal"> · full</span>}
                        </p>
                    </div>
                    <div className="min-w-0">
                        <p className="text-muted-foreground text-xs">Licence</p>
                        <div className="mt-0.5 flex flex-wrap items-center gap-1">
                            <LicenceKindPill kind={shop.licence.kind} />
                            {shop.licence.statuses
                                .filter((s) => s.status !== 'active' && s.status !== 'trial')
                                .map((s) => (
                                    <StatusBadge key={s.status} status={s.status} label={`${s.count} ${s.label.toLowerCase()}`} />
                                ))}
                        </div>
                        <EndsAtText iso={shop.licence.nextEndsAt} prefix="Ends" className="mt-0.5 block text-xs" />
                    </div>
                    <div className="col-span-2 min-w-0 sm:col-span-1">
                        <p className="text-muted-foreground text-xs">Status</p>
                        {health ? (
                            <>
                                <div className="mt-0.5 flex flex-wrap items-center gap-1">
                                    <TillStateBadge state={health.state} label={health.stateLabel} />
                                    {health.syncState !== 'notLinked' && health.syncState !== 'healthy' && (
                                        <SyncStateBadge state={health.syncState} label={`Sync ${health.syncStateLabel.toLowerCase()}`} />
                                    )}
                                </div>
                                <p className="text-muted-foreground mt-0.5 text-xs" title={londonDateTime(health.lastContactAt)}>
                                    {health.syncState === 'notLinked' ? `Heard from ${ago(health.lastContactAt, 'never')}` : `Synced ${ago(health.lastSyncAt, 'never')}`}
                                </p>
                            </>
                        ) : (
                            <StatusPill tone="neutral">{shop.isActive ? 'Not monitored' : 'Closed'}</StatusPill>
                        )}
                    </div>
                </div>
                <ChevronRight className="text-muted-foreground hidden size-4 shrink-0 lg:block" aria-hidden />
            </Link>
        </li>
    );
}
