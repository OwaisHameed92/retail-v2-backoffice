import { formKey, salonSend, telHref } from '@/components/salon-public/api';
import { MarketingTicks, type MarketingChoice } from '@/components/salon-public/fields';
import { SalonLayout, SalonNotice, SalonSection } from '@/components/salon-public/salon-layout';
import { TimePicker, type PickedTime } from '@/components/salon-public/time-picker';
import { type SalonShop } from '@/components/salon-public/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useCallback, useState } from 'react';

interface Appointment {
    id: string;
    status: string | null;
    when: string;
    time: string;
    stylist: string | null;
    stylistId: string | null;
    services: string[];
    firstName: string;
}

interface Props {
    shop: SalonShop;
    link: string | null;
    appointment: Appointment | null;
    stylists: { userId: string; firstName: string; group: string }[];
    changeable?: boolean;
    canMoveOnline?: boolean;
    changeWindowHours?: number;
    consent?: MarketingChoice | null;
    requests?: { id: string; kind: string; status: string; when: string | null; reason: string }[];
}

const KIND = { reschedule: 'Move', cancel: 'Cancel', preferences: 'Marketing choices' } as Record<string, string>;
const STATUS = { pending: 'Waiting for the shop', accepted: 'Done', declined: 'Not possible', cancelled: 'Withdrawn', expired: 'Ran out' } as Record<
    string,
    string
>;

/**
 * The manage link (W3a, salon pack README §8): the appointment as the shop's till holds it; move or cancel until
 * `changeWindowHours` before the start (each a request the shop confirms), "Please ring the shop" inside it; marketing
 * choices any time. A wrong or old link opens nothing.
 */
export default function SalonManage({
    shop,
    link,
    appointment,
    stylists,
    changeable,
    canMoveOnline,
    changeWindowHours,
    consent,
    requests = [],
}: Props) {
    const base = link === null ? '' : `${shop.urls.manageUrl}${link}`;
    const [moving, setMoving] = useState(false);
    const [stylist, setStylist] = useState(appointment?.stylistId ?? 'any');
    const [picked, setPicked] = useState<PickedTime | null>(null);
    const [ticks, setTicks] = useState<MarketingChoice>(consent ?? { sms: false, email: false, whatsApp: false });
    const [busy, setBusy] = useState<string | null>(null);
    const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
    const onPick = useCallback((value: PickedTime | null) => setPicked(value), []);

    if (appointment === null) {
        return (
            <SalonLayout shop={shop} title="Your appointment">
                <SalonNotice tone="warning" title="This link does not open a booking">
                    <p>It may be old, or typed wrongly. {shop.phone !== '' ? `Ring the shop on ${shop.phone}.` : 'Ring the shop.'}</p>
                </SalonNotice>
            </SalonLayout>
        );
    }

    const send = async (what: 'move' | 'cancel' | 'preferences', body: Record<string, unknown>) => {
        setBusy(what);
        const result = await salonSend('POST', `${base}/${what}`, { ...body, clientKey: formKey() });
        setBusy(null);
        setNotice(
            result.ok
                ? {
                      tone: 'success',
                      text:
                          what === 'preferences'
                              ? 'Your choices have been sent to the shop.'
                              : 'Your request has been sent. The shop will confirm it.',
                  }
                : { tone: 'danger', text: Object.values(result.errors)[0] ?? result.message ?? 'Something went wrong.' },
        );
        if (result.ok) {
            setMoving(false);
            setPicked(null);
            router.reload({ only: ['requests', 'changeable', 'appointment'] });
        }
    };

    const cancelled = appointment.status === 'cancelled';

    return (
        <SalonLayout shop={shop} title="Your appointment">
            <div className="space-y-4">
                {notice && <SalonNotice tone={notice.tone} title={notice.text} />}

                <SalonSection title={`${appointment.firstName ? `${appointment.firstName}, your` : 'Your'} appointment`}>
                    <p className="text-lg font-semibold">{appointment.when}</p>
                    <p className="text-lg tabular-nums">{appointment.time}</p>
                    <p className="text-muted-foreground mt-1">
                        {appointment.services.join(', ')}
                        {appointment.stylist ? ` with ${appointment.stylist}` : ''}
                    </p>
                    {cancelled && <p className="text-destructive mt-2 font-medium">This appointment was cancelled.</p>}
                </SalonSection>

                {!cancelled && changeable && (
                    <SalonSection
                        title="Change your appointment"
                        description={`You can move or cancel online until ${changeWindowHours} hours before.`}
                    >
                        {!moving ? (
                            <div className="flex flex-wrap gap-2">
                                {canMoveOnline && (
                                    <Button variant="outline" onClick={() => setMoving(true)}>
                                        Move to another time
                                    </Button>
                                )}
                                <ConfirmDialog
                                    trigger={<Button variant="destructive">Cancel appointment</Button>}
                                    title="Cancel this appointment?"
                                    description={`Your appointment on ${appointment.when} at ${appointment.time} will be cancelled once the shop confirms.`}
                                    confirmLabel="Cancel appointment"
                                    cancelLabel="Keep it"
                                    destructive
                                    onConfirm={() => send('cancel', {})}
                                />
                            </div>
                        ) : (
                            <div className="space-y-4">
                                <div role="radiogroup" aria-label="Stylist" className="flex flex-wrap gap-2">
                                    {[{ userId: 'any', firstName: 'Any stylist' }, ...stylists].map((s) => (
                                        <Button
                                            key={s.userId}
                                            type="button"
                                            role="radio"
                                            aria-checked={stylist === s.userId}
                                            variant={stylist === s.userId ? 'default' : 'outline'}
                                            size="sm"
                                            onClick={() => setStylist(s.userId)}
                                        >
                                            {s.firstName}
                                        </Button>
                                    ))}
                                </div>
                                <TimePicker url={`${base}/times?stylist=${encodeURIComponent(stylist)}`} value={picked} onChange={onPick} />
                                <div className="flex gap-2">
                                    <Button
                                        disabled={picked === null || busy !== null}
                                        onClick={() => picked && send('move', { stylist, date: picked.date, time: picked.time })}
                                    >
                                        {busy === 'move' && <Loader2 className="animate-spin" aria-hidden="true" />}
                                        Request {picked ? picked.label : 'this time'}
                                    </Button>
                                    <Button variant="ghost" onClick={() => setMoving(false)}>
                                        Keep my time
                                    </Button>
                                </div>
                            </div>
                        )}
                    </SalonSection>
                )}

                {!cancelled && !changeable && (
                    <SalonNotice tone="info" title="Please ring the shop to change this appointment">
                        {shop.phone !== '' && (
                            <p>
                                <a className="text-primary font-medium underline" href={telHref(shop.phone)}>
                                    {shop.phone}
                                </a>
                            </p>
                        )}
                    </SalonNotice>
                )}

                {consent && (
                    <SalonSection title="Your marketing choices" description="Change them any time.">
                        <MarketingTicks value={ticks} onChange={setTicks} shopName={shop.name} />
                        <Button className="mt-4" variant="outline" disabled={busy !== null} onClick={() => send('preferences', { ...ticks })}>
                            {busy === 'preferences' && <Loader2 className="animate-spin" aria-hidden="true" />}
                            Save my choices
                        </Button>
                    </SalonSection>
                )}

                {requests.length > 0 && (
                    <SalonSection title="Your requests">
                        <ul className="divide-y">
                            {requests.map((r) => (
                                <li key={r.id} className="flex items-baseline justify-between gap-3 py-2">
                                    <span>
                                        {KIND[r.kind] ?? r.kind}
                                        {r.when ? ` to ${r.when}` : ''}
                                        {r.reason !== '' && <span className="text-muted-foreground block text-xs">{r.reason}</span>}
                                    </span>
                                    <span className="text-muted-foreground text-xs">{STATUS[r.status] ?? r.status}</span>
                                </li>
                            ))}
                        </ul>
                    </SalonSection>
                )}
            </div>
        </SalonLayout>
    );
}
