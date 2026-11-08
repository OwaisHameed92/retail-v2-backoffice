import { ToggleSwitch } from '@/components/app/online-store/toggle-switch';
import { type SalonShop, type SalonStylist } from '@/components/app/salon/types';
import { EmptyState } from '@/components/shared/empty-state';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { RowActions } from '@/components/shared/row-actions';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Badge } from '@/components/ui/badge';
import { formatDate } from '@/lib/country';
import { relativeTime } from '@/lib/relative-time';
import { CalendarClock, CalendarOff, RefreshCw, UserRound } from 'lucide-react';

export type FeedCommand = { kind: 'enable' | 'regenerate' | 'revoke'; shop: SalonShop; stylist: SalonStylist };

interface StylistFeedsCardProps {
    shop: SalonShop;
    /** Show the shop's name in the title (more than one salon shop in view). */
    showShop: boolean;
    /** `${shopId}|${userId}` of the feed being changed. */
    busy: string | null;
    onCommand: (command: FeedCommand) => void;
}

function feedLine(stylist: SalonStylist): string {
    const feed = stylist.feed;

    if (!feed?.on) {
        return feed?.issuedAt ? 'Calendar feed off' : 'No calendar feed yet';
    }

    const used = feed.lastFetchedAt ? `last read by a calendar ${relativeTime(feed.lastFetchedAt)}` : 'not added to a calendar yet';

    return `Address ending …${feed.last4 ?? ''} · ${used}`;
}

/** One salon shop's stylists, each with a "Calendar feed" switch (on → the address is shown once), a new address and off. */
export function StylistFeedsCard({ shop, showShop, busy, onCommand }: StylistFeedsCardProps) {
    return (
        <SectionCard
            flush
            title={showShop ? `Stylists at ${shop.name}` : 'Stylists'}
            actions={
                showShop ? (
                    <StatusPill tone="info">
                        {shop.todayAppointments === 1 ? '1 appointment today' : `${shop.todayAppointments} appointments today`}
                    </StatusPill>
                ) : undefined
            }
            description="Turn on a calendar feed to put a stylist’s own appointments in their phone’s calendar. First names only, no phone numbers or notes."
        >
            {shop.stylists.length === 0 ? (
                <EmptyState
                    icon={UserRound}
                    title="No stylists from the till yet"
                    body="Stylists appear here once the shop’s till sends its team (Salon → Team on the till)."
                    size="sm"
                />
            ) : (
                <ul className="divide-border divide-y">
                    {shop.stylists.map((stylist) => {
                        const key = `${shop.id}|${stylist.userId}`;
                        const on = stylist.feed?.on ?? false;

                        return (
                            <li key={key} className="flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-center sm:gap-4 sm:px-6">
                                <div className="flex min-w-0 flex-1 items-center gap-3">
                                    <InitialsAvatar name={stylist.name} />
                                    <div className="grid min-w-0 gap-0.5">
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <span className="truncate font-medium">{stylist.name}</span>
                                            {!stylist.onTill && <Badge variant="warning">Not on the till any more</Badge>}
                                            {stylist.onTill && !stylist.bookable && <Badge variant="neutral">Not taking clients</Badge>}
                                            {stylist.chairRenter && <Badge variant="info">Chair renter</Badge>}
                                            {stylist.onlineBookable && <Badge variant="success">Online booking</Badge>}
                                        </div>
                                        <span
                                            className="text-muted-foreground truncate text-sm"
                                            title={on && stylist.feed?.issuedAt ? `Address made ${formatDate(stylist.feed.issuedAt)}` : undefined}
                                        >
                                            {feedLine(stylist)}
                                        </span>
                                    </div>
                                </div>
                                <div className="flex items-center justify-between gap-3 sm:justify-end">
                                    <label htmlFor={`feed-${key}`} className="text-muted-foreground text-sm sm:sr-only">
                                        Calendar feed
                                    </label>
                                    <div className="flex items-center gap-2">
                                        <ToggleSwitch
                                            id={`feed-${key}`}
                                            label={`Calendar feed for ${stylist.name}`}
                                            checked={on}
                                            busy={busy === key}
                                            disabled={!on && !stylist.onTill}
                                            onCheckedChange={(next) => onCommand({ kind: next ? 'enable' : 'revoke', shop, stylist })}
                                        />
                                        <RowActions
                                            label={`Calendar feed actions for ${stylist.name}`}
                                            actions={[
                                                {
                                                    label: 'Give it a new address',
                                                    icon: RefreshCw,
                                                    disabled: !on || !stylist.onTill,
                                                    onSelect: () => onCommand({ kind: 'regenerate', shop, stylist }),
                                                },
                                                {
                                                    label: 'Turn the feed off',
                                                    icon: CalendarOff,
                                                    destructive: true,
                                                    disabled: !on,
                                                    onSelect: () => onCommand({ kind: 'revoke', shop, stylist }),
                                                },
                                            ]}
                                        />
                                    </div>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
            <div className="text-muted-foreground flex items-center gap-2 border-t px-4 py-3 text-xs sm:px-6">
                <CalendarClock className="size-3.5 shrink-0" aria-hidden />
                Feeds show 7 days back and 60 days ahead. Cancelled appointments stay, marked cancelled, for 7 days; no-shows are left out.
            </div>
        </SectionCard>
    );
}
