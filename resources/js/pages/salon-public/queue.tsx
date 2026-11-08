import { formKey, minutes, salonSend } from '@/components/salon-public/api';
import { SalonField, SalonTick } from '@/components/salon-public/fields';
import { SalonLayout, SalonNotice, SalonSection } from '@/components/salon-public/salon-layout';
import { type SalonShop } from '@/components/salon-public/types';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { Loader2, UserPlus } from 'lucide-react';
import { useState } from 'react';

interface Props {
    shop: SalonShop;
    open: boolean;
    services: { id: string; name: string; durationMin: number }[];
    stylists: { userId: string; firstName: string }[];
    waiting: number | null;
    walkInMinutesToWait: number | null;
    src: string;
}

const MAX_SERVICES = 10;

/**
 * Queue join from a phone (W3a, salon pack README §4.3, §6): the page the door QR opens. The same rules as the desk's
 * Add: first name (60), phone optional (30, digits and + ( ) -), up to 10 services, any or one stylist, a note (200).
 * When the queue is not taking phone joins: "please ask at the desk".
 */
export default function SalonQueue({ shop, open, services, stylists, waiting, walkInMinutesToWait, src }: Props) {
    const [firstName, setFirstName] = useState('');
    const [phone, setPhone] = useState('');
    const [chosen, setChosen] = useState<string[]>([]);
    const [stylist, setStylist] = useState('any');
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [message, setMessage] = useState<string | null>(null);
    const [sending, setSending] = useState(false);
    const [key] = useState(formKey);

    const join = async () => {
        setSending(true);
        setMessage(null);
        const result = await salonSend<{ redirect: string }>('POST', shop.urls.queueJoinUrl, {
            firstName,
            phone,
            services: chosen,
            stylist,
            note,
            src,
            clientKey: key,
        });

        if (result.ok && result.data) {
            window.location.assign(result.data.redirect);
            return;
        }

        setSending(false);
        setErrors(result.errors);
        setMessage(result.status === 422 ? null : result.message);
    };

    if (!open) {
        return (
            <SalonLayout shop={shop} title="Join the queue">
                <SalonNotice tone="warning" title="The queue is not taking phone joins just now — please ask at the desk" />
            </SalonLayout>
        );
    }

    return (
        <SalonLayout shop={shop} title="Join the queue">
            <div className="space-y-4">
                <SalonNotice tone="info" title="Join the walk-in queue">
                    {waiting !== null && (
                        <p>
                            {waiting === 0 ? 'Nobody is waiting.' : `${waiting} ${waiting === 1 ? 'person is' : 'people are'} waiting.`}{' '}
                            {walkInMinutesToWait !== null ? `About ${minutes(walkInMinutesToWait)} for someone joining now.` : ''}
                        </p>
                    )}
                    <p>We will show your place here, and call you to the desk.</p>
                </SalonNotice>

                <SalonSection title="Your details">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <SalonField
                            id="firstName"
                            label="First name"
                            autoComplete="given-name"
                            maxLength={60}
                            value={firstName}
                            error={errors.firstName}
                            onChange={(e) => setFirstName(e.target.value)}
                        />
                        <SalonField
                            id="phone"
                            label="Mobile number (optional)"
                            type="tel"
                            inputMode="tel"
                            autoComplete="tel"
                            maxLength={30}
                            value={phone}
                            error={errors.phone}
                            hint="We text you when you are nearly next."
                            onChange={(e) => setPhone(e.target.value)}
                        />
                    </div>
                </SalonSection>

                {services.length > 0 && (
                    <SalonSection title="What would you like?" description={`Choose up to ${MAX_SERVICES}, or leave it to the desk.`}>
                        <div className="space-y-2">
                            {services.map((s) => (
                                <SalonTick
                                    key={s.id}
                                    id={`service-${s.id}`}
                                    checked={chosen.includes(s.id)}
                                    onChange={(on) =>
                                        setChosen((ids) => (on ? (ids.length < MAX_SERVICES ? [...ids, s.id] : ids) : ids.filter((x) => x !== s.id)))
                                    }
                                >
                                    {s.name} <span className="text-muted-foreground">{minutes(s.durationMin)}</span>
                                </SalonTick>
                            ))}
                        </div>
                        {errors.services && <p className="text-destructive mt-2 text-xs">{errors.services}</p>}
                    </SalonSection>
                )}

                <SalonSection title="Who would you like?">
                    <div role="radiogroup" aria-label="Stylist" className="flex flex-wrap gap-2">
                        {[{ userId: 'any', firstName: 'Anyone' }, ...stylists].map((s) => (
                            <button
                                key={s.userId}
                                type="button"
                                role="radio"
                                aria-checked={stylist === s.userId}
                                onClick={() => setStylist(s.userId)}
                                className={cn(
                                    'focus-visible:ring-ring/30 rounded-full border px-3 py-1.5 font-medium outline-none focus-visible:ring-[3px]',
                                    stylist === s.userId
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'border-border bg-card hover:border-primary/60',
                                )}
                            >
                                {s.firstName}
                            </button>
                        ))}
                    </div>
                    {errors.stylist && <p className="text-destructive mt-2 text-xs">{errors.stylist}</p>}
                    <div className="mt-4 space-y-1.5">
                        <Label htmlFor="note">Note for the desk (optional)</Label>
                        <Textarea id="note" rows={2} maxLength={200} value={note} onChange={(e) => setNote(e.target.value)} />
                        {errors.note && <p className="text-destructive text-xs">{errors.note}</p>}
                    </div>
                </SalonSection>

                {message && <p className="text-destructive text-center text-xs">{message}</p>}
                <Button className="w-full" size="lg" onClick={join} disabled={sending}>
                    {sending ? <Loader2 className="animate-spin" aria-hidden="true" /> : <UserPlus aria-hidden="true" />}
                    Join the queue
                </Button>
            </div>
        </SalonLayout>
    );
}
