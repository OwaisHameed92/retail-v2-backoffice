import { currencySymbol } from '@/lib/country';
import { cn } from '@/lib/utils';
import { Clock, CreditCard, MapPin, Phone, ShoppingBasket, Timer, Truck } from 'lucide-react';
import { type ReactNode } from 'react';
import { money } from './format';
import { SectionHeading } from './home-sections';
import { COPY, templateOf } from './theme';
import { type ShopInfo, type StoreInfo, type StoreTemplate } from './types';

/* The home page's information sections (OS-8), shared by both templates: how click & collect works, shops and about. */

export function HowItWorksSection({ prepMinutes, template = 'freshMarket' }: { prepMinutes: number | null; template?: StoreTemplate }) {
    const butcher = template === 'butcher';
    const steps = [
        {
            icon: ShoppingBasket,
            title: butcher ? 'Choose your cut' : 'Fill your basket',
            text: butcher
                ? 'Pick the meat and the weight. Weighed cuts show an estimated price.'
                : 'Everything is at the shelf price, straight from our tills.',
        },
        { icon: Timer, title: 'Pick a time', text: `Choose a collection slot. We need about ${prepMinutes ?? 30} minutes to get it ready.` },
        {
            icon: CreditCard,
            title: 'Collect and pay',
            text: butcher
                ? 'We weigh it at the counter and you pay the real weight, by card or cash.'
                : 'Give your order number at the counter and pay there, by card or cash.',
        },
    ];

    return (
        <section id="how-it-works" aria-labelledby="how-title" className="bg-brand-soft scroll-mt-24 rounded-2xl p-6 sm:p-8 lg:p-10">
            <div className="flex flex-col gap-6 lg:flex-row lg:items-center lg:gap-10">
                <div className="flex flex-col gap-2 lg:w-[300px] lg:shrink-0">
                    <span className="text-brand-text text-xs font-semibold tracking-[0.08em] uppercase">Click &amp; collect</span>
                    <h2 id="how-title" className="font-display text-[22px] leading-tight font-bold tracking-[-0.02em] sm:text-[28px]">
                        Shop online, skip the queue
                    </h2>
                    <p className="text-body text-sm leading-relaxed">No account and no card online. You pay in the shop when you collect.</p>
                </div>
                <ol className="grid flex-1 gap-3 sm:grid-cols-3">
                    {steps.map((step, i) => (
                        <li key={step.title} className="flex flex-col gap-2 rounded-xl bg-white p-5">
                            <span className="flex items-center gap-2.5">
                                <span aria-hidden="true" className="bg-brand text-on-brand flex size-9 items-center justify-center rounded-full">
                                    <step.icon className="size-[18px]" />
                                </span>
                                <span className="text-muted text-xs font-semibold">Step {i + 1}</span>
                            </span>
                            <span className="text-[15px] font-semibold">{step.title}</span>
                            <span className="text-muted text-sm leading-relaxed">{step.text}</span>
                        </li>
                    ))}
                </ol>
            </div>
        </section>
    );
}

export function ShopsSection({ shops }: { shops: ShopInfo[] }) {
    if (shops.length === 0) {
        return null;
    }

    const delivers = shops.some((shop) => shop.delivery);

    return (
        <section id="shops" aria-labelledby="shops-title" className="flex scroll-mt-24 flex-col gap-4">
            <SectionHeading
                id="shops-title"
                title={shops.length > 1 ? 'Our shops' : delivers ? 'Collect or get it delivered' : 'Where to collect'}
                sub={shops.length > 1 ? 'Collect from the shop nearest you' : 'Opening hours for collection'}
            />
            <ul className={cn('grid gap-4', shops.length > 1 && 'lg:grid-cols-2')}>
                {shops.map((shop) => (
                    <li key={shop.id} className="min-w-0">
                        <ShopCard shop={shop} />
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** "Today 09:00–17:00" with a dot (green while open now), or "Closed today". */
function TodayChip({ shop }: { shop: ShopInfo }) {
    const today = shop.hours.find((d) => d.day === shop.today);

    if (!shop.today) {
        return null;
    }

    const open = shop.openNow?.open ?? false;

    return (
        <span
            className={cn(
                'inline-flex h-7 items-center gap-1.5 rounded-full px-3 text-xs font-semibold whitespace-nowrap',
                open ? 'bg-brand-soft text-brand-text' : 'bg-paper text-body',
            )}
        >
            <span aria-hidden="true" className={cn('size-2 rounded-full', open ? 'bg-ok' : 'bg-muted')} />
            {today?.hours ? `${open ? 'Open' : 'Today'} ${today.hours.open}–${today.hours.close}` : 'Closed today'}
        </span>
    );
}

function DeliveryNote({ delivery }: { delivery: NonNullable<ShopInfo['delivery']> }) {
    const fee = Number(delivery.fee) > 0 ? `${money(delivery.fee)} delivery` : 'Free delivery';
    const extras = [
        delivery.freeOver ? `free over ${money(delivery.freeOver)}` : null,
        delivery.minimum ? `minimum order ${money(delivery.minimum)}` : null,
    ].filter(Boolean);
    const areas = delivery.areas.slice(0, 8).join(', ') + (delivery.areas.length > 8 ? ` and ${delivery.areas.length - 8} more` : '');

    return (
        <div className="bg-paper flex items-start gap-3 rounded-xl px-4 py-3 text-sm">
            <Truck aria-hidden="true" className="text-brand-text mt-0.5 size-[18px] shrink-0" />
            <div className="flex min-w-0 flex-col gap-0.5">
                <span className="font-semibold">
                    {fee}
                    {extras.length > 0 && <span className="text-body font-normal"> · {extras.join(' · ')}</span>}
                </span>
                {areas && <span className="text-body">Delivers to {areas}</span>}
            </div>
        </div>
    );
}

/**
 * A collection shop (OS-8, redesigned OS-11): its name, a clean one-line address, phone, today's hours as a chip,
 * local delivery when it delivers, and the week's hours as a two-column table (today highlighted). In a wide space
 * the details and the hours sit side by side (container query), in a narrow one they stack.
 */
export function ShopCard({ shop, children }: { shop: ShopInfo; children?: ReactNode }) {
    return (
        <article className="border-line @container h-full rounded-2xl border bg-white">
            <div className="grid h-full gap-5 p-5 sm:p-6 @xl:grid-cols-[minmax(0,1fr)_minmax(15rem,18rem)] @xl:gap-8">
                <div className="flex min-w-0 flex-col gap-3">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <h3 className="font-display text-xl leading-tight font-bold tracking-[-0.01em] break-words">{shop.name}</h3>
                        <TodayChip shop={shop} />
                    </div>
                    {shop.address && (
                        <p className="text-body flex items-start gap-2 text-[15px] leading-snug">
                            <MapPin aria-hidden="true" className="text-brand-text mt-0.5 size-[18px] shrink-0" />
                            <span className="min-w-0">{shop.address}</span>
                        </p>
                    )}
                    {shop.phone && (
                        <a
                            href={`tel:${shop.phone.replace(/\s+/g, '')}`}
                            className="text-brand-text -my-2 flex min-h-11 items-center gap-2 self-start text-[15px] font-semibold hover:underline"
                        >
                            <Phone aria-hidden="true" className="size-[18px] shrink-0" />
                            {shop.phone}
                        </a>
                    )}
                    {shop.openNow && !shop.openNow.open && <p className="text-muted text-sm">{shop.openNow.label}</p>}
                    {shop.delivery && <DeliveryNote delivery={shop.delivery} />}
                    {children}
                </div>
                <div className="min-w-0">
                    <p className="text-muted mb-2 text-xs font-semibold tracking-[0.06em] uppercase">Collection hours</p>
                    <dl className="divide-line grid divide-y text-sm">
                        {shop.hours.map((day) => {
                            const isToday = day.day === shop.today;

                            return (
                                <div
                                    key={day.day}
                                    className={cn('flex items-center justify-between gap-4 px-2 py-1.5', isToday && 'bg-brand-soft rounded-md font-semibold')}
                                >
                                    <dt>
                                        {day.day}
                                        {isToday && <span className="sr-only"> (today)</span>}
                                    </dt>
                                    <dd className={day.hours ? 'tabular-nums' : 'text-muted'}>
                                        {day.hours ? `${day.hours.open}–${day.hours.close}` : 'Closed'}
                                    </dd>
                                </div>
                            );
                        })}
                    </dl>
                </div>
            </div>
        </article>
    );
}

export function AboutSection({ store, prepMinutes, text }: { store: StoreInfo; prepMinutes: number | null; text: string | null }) {
    const copy = COPY[templateOf(store.template)];
    const facts = [
        { big: `${prepMinutes ?? 30} min`, title: 'Ready to collect', text: 'We get your order ready for the time you choose.' },
        { big: copy.priceFact.big ?? `${currencySymbol()}0`, title: copy.priceFact.title, text: copy.priceFact.text },
        { big: 'Pay', title: 'At the counter', text: 'Card or cash when you collect. Nothing to pay online.' },
    ];

    return (
        <section aria-labelledby="about-title" className="grid gap-4 lg:grid-cols-[1.2fr_2fr]">
            <div className="border-line flex flex-col justify-center gap-2 rounded-2xl border bg-white p-6 sm:p-8">
                <h2 id="about-title" className="font-display text-[22px] leading-tight font-bold tracking-[-0.02em] break-words sm:text-[26px]">
                    About {store.name}
                </h2>
                <p className="text-body text-[15px] leading-relaxed whitespace-pre-line">{text ?? copy.aboutLead}</p>
            </div>
            <ul className="grid gap-4 sm:grid-cols-3">
                {facts.map((fact) => (
                    <li key={fact.title} className="border-line flex flex-col gap-1.5 rounded-2xl border bg-white p-5">
                        <span className="font-display text-brand-text text-[26px] leading-none font-bold tracking-[-0.02em]">{fact.big}</span>
                        <span className="text-[15px] font-semibold">{fact.title}</span>
                        <span className="text-muted text-sm leading-relaxed">{fact.text}</span>
                    </li>
                ))}
            </ul>
        </section>
    );
}

export function ClockNote({ children }: { children: ReactNode }) {
    return (
        <p className="text-body flex items-center gap-2 text-sm font-semibold">
            <Clock aria-hidden="true" className="size-4 shrink-0" />
            {children}
        </p>
    );
}
