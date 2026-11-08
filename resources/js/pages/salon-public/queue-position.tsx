import { minutes } from '@/components/salon-public/api';
import { SalonLayout, SalonNotice } from '@/components/salon-public/salon-layout';
import { type SalonShop } from '@/components/salon-public/types';
import { usePoll } from '@inertiajs/react';
import { BellRing, Loader2 } from 'lucide-react';
import { useEffect } from 'react';

interface Position {
    state: 'sent' | 'dropped' | 'refused' | 'waiting' | 'called' | 'inService' | 'done' | 'left' | 'noShow';
    ticket: string;
    position: number | null;
    minutesToWait: number | null;
    estimatedStart: string | null;
    message: string;
    firstName: string;
    joinedAt: string;
}

const FINISHED = ['dropped', 'refused', 'done', 'left', 'noShow'];

function ordinal(n: number): string {
    const rest = n % 100;
    const suffix = rest >= 11 && rest <= 13 ? 'th' : (({ 1: 'st', 2: 'nd', 3: 'rd' } as Record<number, string>)[n % 10] ?? 'th');

    return `${n}${suffix}`;
}

/**
 * "My position" (W3a, salon pack README §4.4, §4.5): read again every 20 seconds from the till's latest queue push.
 * Only this client's own ticket, never anyone else's name or phone. `called`: "Please come to the desk now".
 */
export default function SalonQueuePosition({ shop, position }: { shop: SalonShop; position: Position }) {
    const finished = FINISHED.includes(position.state);
    const { stop } = usePoll(20000, { only: ['position'] }, { autoStart: !finished });

    useEffect(() => {
        if (finished) {
            stop();
        }
    }, [finished, stop]);

    return (
        <SalonLayout shop={shop} title="Your place in the queue">
            <div className="space-y-4" aria-live="polite">
                {position.state === 'sent' && (
                    <SalonNotice tone="info" title={`Thanks${position.firstName ? `, ${position.firstName}` : ''} — joining the queue`}>
                        <p className="flex items-center gap-2">
                            <Loader2 className="size-4 animate-spin" aria-hidden="true" />
                            Waiting for the desk to add you. This takes a few seconds.
                        </p>
                    </SalonNotice>
                )}
                {position.state === 'dropped' && (
                    <SalonNotice tone="warning" title="We could not add you to the queue from your phone — please ask at the desk" />
                )}
                {position.state === 'refused' && (
                    <SalonNotice tone="warning" title="You could not join the queue">
                        <p>{position.message}</p>
                    </SalonNotice>
                )}
                {position.state === 'called' && (
                    <div role="alert" className="border-success/30 bg-success-soft rounded-xl border p-6 text-center">
                        <BellRing className="text-success mx-auto size-8" aria-hidden="true" />
                        <p className="mt-2 text-xl font-semibold">Please come to the desk now</p>
                        {position.ticket !== '' && <p className="text-muted-foreground mt-1">Ticket {position.ticket}</p>}
                    </div>
                )}
                {position.state === 'inService' && <SalonNotice tone="success" title="You are being looked after now" />}
                {position.state === 'done' && <SalonNotice tone="success" title="Thanks for visiting" />}
                {(position.state === 'left' || position.state === 'noShow') && (
                    <SalonNotice tone="warning" title="You are no longer in the queue — please ask at the desk" />
                )}

                {position.state === 'waiting' && (
                    <div className="border-border bg-card rounded-xl border p-6 text-center shadow-xs">
                        {position.ticket !== '' && <p className="text-muted-foreground">Ticket {position.ticket}</p>}
                        <p className="mt-1 text-4xl font-semibold tabular-nums">
                            {position.position === 1
                                ? 'Next'
                                : position.position !== null && position.position > 0
                                  ? ordinal(position.position)
                                  : '—'}
                        </p>
                        <p className="text-muted-foreground mt-1">in the queue</p>
                        {position.minutesToWait !== null && (
                            <p className="mt-3">
                                About {minutes(position.minutesToWait)}
                                {position.estimatedStart ? ` (around ${position.estimatedStart})` : ''}
                            </p>
                        )}
                    </div>
                )}

                {!finished && (
                    <p className="text-muted-foreground text-center text-xs">This page updates by itself. Joined at {position.joinedAt}.</p>
                )}
            </div>
        </SalonLayout>
    );
}
