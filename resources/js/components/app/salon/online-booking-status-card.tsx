import { type OnlineBookingStatus } from '@/components/app/salon/types';
import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Badge } from '@/components/ui/badge';
import { formatDateTime } from '@/lib/country';
import { Globe } from 'lucide-react';

/**
 * Slot for the online booking page's status: whether the till is accepting, when it was last heard from, and the
 * booking and queue links. Salon W3a fills `status` from the till's booking page and heartbeat; until then this card
 * says the page is not live yet (with a muted "Soon" badge), never a dead button.
 */
export function OnlineBookingStatusCard({ status }: { status: OnlineBookingStatus | null }) {
    return (
        <SectionCard
            title={
                <span className="inline-flex items-center gap-2">
                    Online booking
                    {status === null && <Badge variant="neutral">Soon</Badge>}
                </span>
            }
            description="Your online booking page, the walk-in queue from a phone, and whether the till is taking requests."
        >
            {status === null ? (
                <div className="flex items-start gap-3 text-sm">
                    <span className="bg-muted text-muted-foreground flex size-9 shrink-0 items-center justify-center rounded-lg" aria-hidden>
                        <Globe className="size-4" />
                    </span>
                    <p className="text-muted-foreground">
                        Once your till publishes its booking page, this shows whether it is taking requests, when it was last online, and the links to
                        share for booking and for joining the queue.
                    </p>
                </div>
            ) : (
                <dl className="grid gap-3 text-sm sm:grid-cols-2">
                    {status.shopName && (
                        <div className="grid gap-1 sm:col-span-2">
                            <dt className="text-muted-foreground">Shop</dt>
                            <dd>{status.shopName}</dd>
                        </div>
                    )}
                    <div className="grid gap-1">
                        <dt className="text-muted-foreground">Taking requests</dt>
                        <dd>
                            <StatusBadge
                                status={!status.accepting ? 'inactive' : status.state === 'offline' ? 'pending' : 'active'}
                                label={!status.accepting ? 'Closed' : status.state === 'offline' ? 'Request only (till away)' : 'Yes'}
                            />
                        </dd>
                    </div>
                    <div className="grid gap-1">
                        <dt className="text-muted-foreground">Till last heard from</dt>
                        <dd className="tabular-nums">{formatDateTime(status.lastHeardAt)}</dd>
                    </div>
                    {status.bookingUrl && (
                        <div className="grid gap-1 sm:col-span-2">
                            <dt className="text-muted-foreground">Booking page</dt>
                            <dd className="truncate font-mono text-xs">{status.bookingUrl}</dd>
                        </div>
                    )}
                    {status.queueJoinUrl && (
                        <div className="grid gap-1 sm:col-span-2">
                            <dt className="text-muted-foreground">Queue join page</dt>
                            <dd className="truncate font-mono text-xs">{status.queueJoinUrl}</dd>
                        </div>
                    )}
                </dl>
            )}
        </SectionCard>
    );
}
