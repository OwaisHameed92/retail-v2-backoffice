import { formKey, minutes, money, salonSend, telHref } from '@/components/salon-public/api';
import { DetailsForm, emptyDetails, type ClientDetails } from '@/components/salon-public/details-form';
import { SalonLayout, SalonNotice, SalonSection } from '@/components/salon-public/salon-layout';
import { ServicePicker } from '@/components/salon-public/service-picker';
import { ableStylists, basketFor, choiceIds, StylistPicker } from '@/components/salon-public/stylist-picker';
import { TimePicker, type PickedTime } from '@/components/salon-public/time-picker';
import {
    type BasketLine,
    type BookingRules,
    type SalonShop,
    type Service,
    type ServiceOption,
    type ShopStatus,
    type Stylist,
} from '@/components/salon-public/types';
import { Button } from '@/components/ui/button';
import { Loader2, Phone, Send } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

interface Props {
    shop: SalonShop;
    status: ShopStatus;
    queueOpen: boolean;
    rules: BookingRules;
    groups: string[];
    services: Service[];
    stylists: Stylist[];
    embed: boolean;
    src: string;
}

/**
 * The public booking page `{bookingUrl}` (W3a, salon pack README §4.1, §6, §10): services → stylist → day and time →
 * details. Only the till's published times are offered; a request is sent to the shop, which confirms it, so the page
 * never says "booked". Offline: "request only". Closed: the shop's phone.
 */
export default function SalonBook({ shop, status, queueOpen, rules, groups, services, stylists, embed, src }: Props) {
    const [basket, setBasket] = useState<BasketLine[]>([]);
    const [addOns, setAddOns] = useState<string[]>([]);
    const [stylist, setStylist] = useState('any');
    const [picked, setPicked] = useState<PickedTime | null>(null);
    const [details, setDetails] = useState<ClientDetails>(() => emptyDetails(rules.questions.length));
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [message, setMessage] = useState<string | null>(null);
    const [sending, setSending] = useState(false);
    const [key] = useState(formKey);

    const toggle = (service: Service, option: ServiceOption) => {
        setBasket((lines) => {
            const same = lines.find((l) => l.service.productId === service.productId);
            if (same?.option.productId === option.productId) {
                setAddOns((ids) => ids.filter((id) => !service.addOns.some((a) => a.productId === id)));
                return lines.filter((l) => l.service.productId !== service.productId);
            }
            return same ? lines.map((l) => (l === same ? { service, option } : l)) : [...lines, { service, option }].slice(0, 10);
        });
    };

    const addOnTotal = useMemo(() => {
        const all = services.flatMap((s) => s.addOns).filter((a) => addOns.includes(a.productId));
        return { price: all.reduce((sum, a) => sum + Number(a.price), 0), minutes: all.reduce((sum, a) => sum + a.extraMin, 0) };
    }, [services, addOns]);

    // A named stylist who does not do the new basket falls back to "any".
    const able = ableStylists(stylists, basket);
    const choice = stylist === 'any' || stylist === 'female' || stylist === 'male' || able.some((s) => s.userId === stylist) ? stylist : 'any';
    const summary = basketFor(basket, addOnTotal, choiceIds(choice, able));

    const timesUrl = useMemo(() => {
        if (basket.length === 0) {
            return null;
        }
        const query = new URLSearchParams();
        basket.forEach((l) => query.append('options[]', l.option.productId));
        addOns.forEach((id) => query.append('addOns[]', id));
        query.set('stylist', choice);
        return `${shop.urls.bookingUrl}/times?${query.toString()}`;
    }, [basket, addOns, choice, shop.urls.bookingUrl]);

    const onPick = useCallback((value: PickedTime | null) => setPicked(value), []);

    const send = async () => {
        if (picked === null || sending) {
            return;
        }
        setSending(true);
        setMessage(null);
        const result = await salonSend<{ redirect: string }>('POST', `${shop.urls.bookingUrl}${embed ? '?embed=1' : ''}`, {
            options: basket.map((l) => l.option.productId),
            addOns,
            stylist: choice,
            date: picked.date,
            time: picked.time,
            firstName: details.firstName,
            lastName: details.lastName,
            phone: details.phone,
            email: details.email,
            notes: details.notes,
            answers: details.answers,
            patchTest: details.patchTest,
            terms: details.terms,
            marketingSms: details.marketing.sms,
            marketingEmail: details.marketing.email,
            marketingWhatsApp: details.marketing.whatsApp,
            src,
            clientKey: key,
        });

        if (result.ok && result.data) {
            window.location.assign(result.data.redirect);
            return;
        }

        setSending(false);
        setErrors(result.errors);
        setMessage(result.message);
        if (result.errors.time) {
            setPicked(null);
        }
    };

    if (status === 'closed') {
        return (
            <SalonLayout shop={shop} title="Book online" embed={embed}>
                <SalonNotice tone="warning" title={`${shop.name} is not taking online bookings just now`}>
                    {shop.phone !== '' ? (
                        <p>
                            Ring the shop to book:{' '}
                            <a className="text-primary font-medium underline" href={telHref(shop.phone)}>
                                {shop.phone}
                            </a>
                        </p>
                    ) : (
                        <p>Ring or visit the shop to book.</p>
                    )}
                </SalonNotice>
            </SalonLayout>
        );
    }

    const needsPatchTest = basket.some((l) => l.service.needsPatchTest);

    return (
        <SalonLayout shop={shop} title="Book online" embed={embed}>
            <div className="space-y-4">
                {status === 'offline' && (
                    <SalonNotice tone="info" title="Request this time — the shop will confirm">
                        <p>The shop's till is away just now. Send your request and the shop will confirm it when it is back online.</p>
                    </SalonNotice>
                )}
                {queueOpen && !embed && (
                    <p className="text-muted-foreground text-xs">
                        Walking in today?{' '}
                        <a className="text-primary font-medium underline" href={shop.urls.queueJoinUrl}>
                            Join the queue from your phone
                        </a>
                    </p>
                )}

                <SalonSection step={1} title="Choose services">
                    <ServicePicker
                        services={services}
                        groups={groups}
                        basket={basket}
                        addOns={addOns}
                        onToggle={toggle}
                        onAddOn={(id, on) => setAddOns((ids) => (on ? [...ids, id] : ids.filter((x) => x !== id)))}
                    />
                </SalonSection>

                {basket.length > 0 && (
                    <SalonSection step={2} title="Choose a stylist">
                        <StylistPicker stylists={stylists} basket={basket} addOnTotal={addOnTotal} value={choice} onChange={setStylist} />
                    </SalonSection>
                )}

                {basket.length > 0 && (
                    <SalonSection
                        step={3}
                        title="Choose a day and time"
                        description={rules.minNoticeMin > 0 ? `Book at least ${minutes(rules.minNoticeMin)} ahead.` : undefined}
                    >
                        <TimePicker url={timesUrl} value={picked} onChange={onPick} />
                        {errors.time && <p className="text-destructive mt-2 text-xs">{errors.time}</p>}
                    </SalonSection>
                )}

                {picked !== null && (
                    <SalonSection step={4} title="Your details">
                        <DetailsForm
                            rules={rules}
                            shopName={shop.name}
                            needsPatchTest={needsPatchTest}
                            value={details}
                            errors={errors}
                            onChange={setDetails}
                        />
                    </SalonSection>
                )}

                {picked !== null && (
                    <div className="border-border bg-card sticky bottom-0 -mx-4 space-y-3 border-t p-4 shadow-sm sm:static sm:mx-0 sm:rounded-xl sm:border">
                        <div className="flex items-baseline justify-between gap-3">
                            <div className="min-w-0">
                                <p className="font-medium">{picked.label}</p>
                                <p className="text-muted-foreground truncate text-xs">
                                    {[
                                        ...basket.map((l) => `${l.service.name}${l.option.label ? ` (${l.option.label})` : ''}`),
                                        ...services
                                            .flatMap((s) => s.addOns)
                                            .filter((a) => addOns.includes(a.productId))
                                            .map((a) => a.name),
                                    ].join(', ')}{' '}
                                    · {minutes(summary.minutes)}
                                </p>
                            </div>
                            <p className="font-semibold tabular-nums">
                                {summary.from ? 'from ' : ''}
                                {money(summary.price)}
                            </p>
                        </div>
                        {message && <p className="text-destructive text-xs">{message}</p>}
                        <Button className="w-full" size="lg" onClick={send} disabled={sending}>
                            {sending ? <Loader2 className="animate-spin" aria-hidden="true" /> : <Send aria-hidden="true" />}
                            {status === 'offline' || !rules.autoAccept ? 'Request this time' : 'Send booking request'}
                        </Button>
                        <p className="text-muted-foreground text-center text-xs">
                            {status === 'offline' || !rules.autoAccept
                                ? 'The shop will confirm your request.'
                                : 'The shop confirms your booking in a minute.'}{' '}
                            Your time is held for {minutes(rules.holdMin)}.
                        </p>
                    </div>
                )}

                {services.length === 0 && (
                    <SalonNotice tone="info" title="No services can be booked online yet">
                        {shop.phone !== '' && (
                            <p>
                                <Phone className="mr-1 inline size-3.5" aria-hidden="true" />
                                Ring the shop on {shop.phone}.
                            </p>
                        )}
                    </SalonNotice>
                )}
            </div>
        </SalonLayout>
    );
}
