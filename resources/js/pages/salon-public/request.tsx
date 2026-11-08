import { salonSend, telHref } from '@/components/salon-public/api';
import { SalonLayout, SalonNotice, SalonSection } from '@/components/salon-public/salon-layout';
import { type SalonShop } from '@/components/salon-public/types';
import { Button } from '@/components/ui/button';
import { router, usePoll } from '@inertiajs/react';
import { CalendarCheck2, Clock, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';

interface RequestView {
    reference: string;
    status: 'pending' | 'accepted' | 'declined' | 'cancelled' | 'expired';
    when: string;
    time: string;
    stylist: string | null;
    services: string[];
    firstName: string;
    reason: string;
    autoAccept: boolean;
    offline: boolean;
    overdue: boolean;
    manageUrl: string | null;
}

/**
 * "Request sent, waiting for the shop" (W3a, salon pack README §2): read again every 5 seconds while the request is
 * pending. Never "booked" until the till accepts; declined shows the shop's reason as it wrote it. The client may
 * withdraw a request the shop has not answered.
 */
export default function SalonRequest({
    shop,
    request,
    requestId,
    embed,
}: {
    shop: SalonShop;
    request: RequestView;
    requestId: string;
    embed: boolean;
}) {
    const pending = request.status === 'pending';
    const [withdrawing, setWithdrawing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const { stop } = usePoll(5000, { only: ['request'] }, { autoStart: pending });

    useEffect(() => {
        if (!pending) {
            stop();
        }
    }, [pending, stop]);

    const withdraw = async () => {
        setWithdrawing(true);
        const result = await salonSend('POST', `${shop.urls.bookingUrl}/r/${requestId}/withdraw`, {});
        setWithdrawing(false);
        setError(result.ok ? null : (result.errors.request ?? result.message));
        router.reload({ only: ['request'] });
    };

    return (
        <SalonLayout shop={shop} title="Your booking request" embed={embed}>
            <div className="space-y-4">
                {pending && (
                    <SalonNotice tone="info" title={`Thanks${request.firstName ? `, ${request.firstName}` : ''} — your request is with the shop`}>
                        <p className="flex items-center gap-2">
                            <Loader2 className="size-4 animate-spin" aria-hidden="true" />
                            {request.offline
                                ? 'The shop will confirm when its till is back online. You can leave this page; the shop will message you.'
                                : request.autoAccept && !request.overdue
                                  ? 'Waiting for the shop to confirm. This usually takes under a minute.'
                                  : 'Waiting for the shop to confirm. The shop will message you when it has.'}
                        </p>
                        <p className="text-xs">This is a request, not yet a booking.</p>
                    </SalonNotice>
                )}
                {request.status === 'accepted' && (
                    <SalonNotice tone="success" title="Booked — the shop has confirmed your appointment">
                        <p>We look forward to seeing you.</p>
                    </SalonNotice>
                )}
                {request.status === 'declined' && (
                    <SalonNotice tone="danger" title="The shop could not take this booking">
                        {request.reason !== '' && <p>{request.reason}</p>}
                        <p>
                            <a className="text-primary font-medium underline" href={shop.urls.bookingUrl + (embed ? '?embed=1' : '')}>
                                Choose another time
                            </a>
                            {shop.phone !== '' && (
                                <>
                                    {' '}
                                    or ring{' '}
                                    <a className="text-primary font-medium underline" href={telHref(shop.phone)}>
                                        {shop.phone}
                                    </a>
                                </>
                            )}
                            .
                        </p>
                    </SalonNotice>
                )}
                {(request.status === 'cancelled' || request.status === 'expired') && (
                    <SalonNotice
                        tone="warning"
                        title={request.status === 'cancelled' ? 'You withdrew this request' : 'This request ran out before the shop answered'}
                    >
                        <p>
                            <a className="text-primary font-medium underline" href={shop.urls.bookingUrl + (embed ? '?embed=1' : '')}>
                                Make a new request
                            </a>
                        </p>
                    </SalonNotice>
                )}

                <SalonSection title={request.status === 'accepted' ? 'Your appointment' : 'What you asked for'}>
                    <dl className="grid gap-3 sm:grid-cols-2">
                        <div className="flex items-start gap-2">
                            <CalendarCheck2 className="text-muted-foreground mt-0.5 size-4" aria-hidden="true" />
                            <div>
                                <dt className="text-muted-foreground text-xs">Day</dt>
                                <dd className="font-medium">{request.when}</dd>
                            </div>
                        </div>
                        <div className="flex items-start gap-2">
                            <Clock className="text-muted-foreground mt-0.5 size-4" aria-hidden="true" />
                            <div>
                                <dt className="text-muted-foreground text-xs">Time</dt>
                                <dd className="font-medium tabular-nums">{request.time}</dd>
                            </div>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">Services</dt>
                            <dd>{request.services.join(', ')}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">Stylist</dt>
                            <dd>{request.stylist ?? 'Any stylist'}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground text-xs">Reference</dt>
                            <dd className="font-mono text-xs">{request.reference}</dd>
                        </div>
                    </dl>
                </SalonSection>

                {request.manageUrl && (
                    <a href={request.manageUrl} className="text-primary block text-center font-medium underline">
                        Change or cancel this appointment
                    </a>
                )}

                {pending && (
                    <div className="text-center">
                        <Button variant="outline" onClick={withdraw} disabled={withdrawing}>
                            {withdrawing && <Loader2 className="animate-spin" aria-hidden="true" />}
                            Withdraw my request
                        </Button>
                        {error && <p className="text-destructive mt-2 text-xs">{error}</p>}
                    </div>
                )}
            </div>
        </SalonLayout>
    );
}
