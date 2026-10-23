import { currencySymbol } from '@/lib/country';
import { Link } from '@inertiajs/react';
import { ArrowRight, Clock, MapPin, Phone } from 'lucide-react';
import { type ReactNode } from 'react';
import { initialOf, tintFor } from './format';
import { ProductGrid } from './product-card';
import { type ShopInfo, type StoreInfo, type StoreProduct } from './types';

export function SectionHeading({ title, sub, link, id }: { title: string; sub?: string; link?: { href: string; label: string }; id?: string }) {
    return (
        <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-2">
            <div className="flex flex-col gap-1.5">
                <h2 id={id} className="font-display text-[28px] leading-none font-extrabold tracking-[-0.035em] sm:text-[44px]">
                    {title}
                </h2>
                {sub && <p className="text-muted text-sm sm:text-base">{sub}</p>}
            </div>
            {link && (
                <Link href={link.href} className="text-brand inline-flex min-h-11 items-center gap-1.5 font-bold underline-offset-4 hover:underline">
                    {link.label}
                    <ArrowRight aria-hidden="true" className="size-4" />
                </Link>
            )}
        </div>
    );
}

export function HeroSection({
    store,
    headline,
    bannerUrl,
    nextCollection,
    prepMinutes,
}: {
    store: StoreInfo;
    headline: string | null;
    bannerUrl: string;
    nextCollection: string | null;
    prepMinutes: number | null;
}) {
    const start = store.departments[0]?.url ?? store.urls.search;

    return (
        <section
            aria-labelledby="hero-title"
            className="bg-tile relative flex min-h-[420px] flex-col justify-end overflow-hidden rounded-[30px] sm:min-h-[480px] md:min-h-[580px] md:justify-center md:rounded-[36px]"
        >
            <img src={bannerUrl} alt="" className="absolute inset-0 size-full object-cover object-[78%_50%] md:object-[70%_50%]" />
            <div aria-hidden="true" className="absolute inset-0 hidden bg-gradient-to-r from-white/90 via-white/55 to-transparent md:block" />
            <div className="relative m-2.5 flex flex-col gap-4 rounded-[22px] bg-white p-5 sm:m-4 sm:p-7 md:m-0 md:max-w-[640px] md:gap-6 md:bg-transparent md:p-14">
                <span className="bg-ink flex items-center gap-2 self-start rounded-full px-3.5 py-2 text-[13px] font-semibold text-white">
                    <span aria-hidden="true" className="bg-lime size-2 rounded-full" />
                    {store.takingOrders ? (nextCollection ? `Next collection ${nextCollection}` : 'Click & collect') : 'Browse online, shop in store'}
                </span>
                <h1
                    id="hero-title"
                    className="font-display text-[34px] leading-[0.96] font-extrabold tracking-[-0.045em] text-balance sm:text-5xl md:text-6xl lg:text-[84px] lg:leading-[0.95]"
                >
                    {headline ?? 'Your local shop, ready when you are.'}
                </h1>
                <p className="text-body max-w-[460px] text-base leading-relaxed sm:text-[19px]">
                    Order online and collect{store.shop ? ` from ${store.shop.name}` : ''}
                    {prepMinutes ? `, ready in about ${prepMinutes} minutes` : ''}. Pay at the counter when you pick it up.
                </p>
                <div className="flex flex-wrap gap-3">
                    <Link
                        href={start}
                        className="bg-brand text-on-brand inline-flex min-h-12 items-center gap-2.5 rounded-full px-6 font-bold transition hover:brightness-110 sm:min-h-[58px] sm:px-8 sm:text-base"
                    >
                        Start shopping
                        <ArrowRight aria-hidden="true" className="size-[18px]" strokeWidth={2.4} />
                    </Link>
                    <a
                        href="#how-it-works"
                        className="text-ink ring-line hover:bg-paper hidden min-h-[58px] items-center rounded-full bg-white px-6 font-bold ring-1 transition md:inline-flex"
                    >
                        How click &amp; collect works
                    </a>
                </div>
                <ul className="hidden flex-wrap gap-2 md:flex">
                    {['Same prices as in the shop', 'Pay when you collect', 'No account needed'].map((chip) => (
                        <li key={chip} className="rounded-full bg-white/90 px-3 py-1.5 text-[13px] font-semibold">
                            {chip}
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}

export function DepartmentsSection({ store }: { store: StoreInfo }) {
    if (store.departments.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="aisles-title" className="flex flex-col gap-5">
            <SectionHeading id="aisles-title" title="Shop by aisle" link={{ href: store.urls.search, label: 'All products' }} />
            <ul className="grid grid-cols-4 gap-x-2.5 gap-y-4 sm:grid-cols-[repeat(auto-fill,minmax(140px,1fr))] sm:gap-4">
                {store.departments.map((department) => (
                    <li key={department.id}>
                        <Link href={department.url} className="group text-ink flex flex-col items-center gap-2 text-center sm:gap-3">
                            <span
                                aria-hidden="true"
                                className="font-display text-ink/70 flex aspect-square w-full items-center justify-center rounded-full text-2xl font-extrabold transition-transform group-hover:scale-[1.04] sm:text-5xl"
                                style={{ backgroundColor: tintFor(department.name) }}
                            >
                                {initialOf(department.name)}
                            </span>
                            <span className="text-xs leading-tight font-semibold sm:text-[15px] sm:font-bold">{department.name}</span>
                            <span className="sr-only">, {department.count} products</span>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}

export function FeaturedSection({ store, products }: { store: StoreInfo; products: StoreProduct[] }) {
    if (products.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="featured-title" className="flex flex-col gap-5 sm:gap-6">
            <SectionHeading
                id="featured-title"
                title="On the shelves now"
                sub="Prices and stock come live from the shop's tills"
                link={{ href: store.urls.search, label: 'See all' }}
            />
            <ProductGrid products={products} />
        </section>
    );
}

export function HowItWorksSection({ prepMinutes }: { prepMinutes: number | null }) {
    const steps: { n: string; title: string; text: string }[] = [
        { n: '1', title: 'Fill your basket', text: 'Everything is at the shelf price, straight from our tills.' },
        { n: '2', title: 'Pick a time', text: `Choose a collection slot. We need about ${prepMinutes ?? 30} minutes to get it ready.` },
        { n: '3', title: 'Collect and pay', text: 'Give your order number at the counter and pay there, by card or cash.' },
    ];

    return (
        <section
            id="how-it-works"
            aria-labelledby="how-title"
            className="bg-brand text-on-brand grid scroll-mt-6 gap-8 rounded-[30px] p-6 sm:p-10 md:rounded-[36px] lg:grid-cols-[1fr_1.4fr] lg:items-center lg:p-14"
        >
            <div className="flex flex-col gap-4">
                <span className="bg-lime text-ink self-start rounded-full px-3 py-1.5 text-[13px] font-bold">Click &amp; collect</span>
                <h2 id="how-title" className="font-display text-4xl leading-[0.98] font-extrabold tracking-[-0.04em] sm:text-[56px]">
                    Shop online, skip the queue.
                </h2>
                <p className="max-w-[460px] text-base leading-relaxed opacity-90 sm:text-lg">
                    No account, no card online. You pay in the shop when you collect.
                </p>
            </div>
            <ol className="grid gap-3 sm:grid-cols-3">
                {steps.map((step) => (
                    <li key={step.n} className="text-ink flex flex-col gap-3 rounded-[24px] bg-white p-5 sm:p-6">
                        <span aria-hidden="true" className="bg-ink text-lime flex size-10 items-center justify-center rounded-full font-extrabold">
                            {step.n}
                        </span>
                        <span className="font-display text-xl font-extrabold tracking-tight">{step.title}</span>
                        <span className="text-muted text-sm leading-relaxed">{step.text}</span>
                    </li>
                ))}
            </ol>
        </section>
    );
}

export function ShopsSection({ shops }: { shops: ShopInfo[] }) {
    if (shops.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="shops-title" className="flex flex-col gap-5 sm:gap-6">
            <SectionHeading id="shops-title" title={shops.length > 1 ? 'Our shops' : 'Where to collect'} sub="Collection hours" />
            <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {shops.map((shop) => (
                    <li key={shop.id}>
                        <ShopCard shop={shop} />
                    </li>
                ))}
            </ul>
        </section>
    );
}

export function ShopCard({ shop, children }: { shop: ShopInfo; children?: ReactNode }) {
    return (
        <div className="flex h-full flex-col gap-4 rounded-[28px] bg-white p-6">
            <div className="flex flex-col gap-2">
                <h3 className="font-display text-2xl font-extrabold tracking-tight">{shop.name}</h3>
                {shop.address && (
                    <p className="text-body flex items-start gap-2">
                        <MapPin aria-hidden="true" className="text-muted mt-0.5 size-[18px] shrink-0" />
                        {shop.address}
                    </p>
                )}
                {shop.phone && (
                    <a
                        href={`tel:${shop.phone.replace(/\s+/g, '')}`}
                        className="text-brand flex min-h-11 items-center gap-2 self-start font-semibold hover:underline"
                    >
                        <Phone aria-hidden="true" className="size-[18px] shrink-0" />
                        {shop.phone}
                    </a>
                )}
            </div>
            <dl className="bg-paper grid grid-cols-[auto_1fr] gap-x-6 gap-y-1.5 rounded-2xl p-4 text-sm">
                {shop.hours.map((day) => (
                    <div key={day.day} className="contents">
                        <dt className="font-semibold">{day.day}</dt>
                        <dd className={day.hours ? 'tabular-nums' : 'text-muted'}>
                            {day.hours ? `${day.hours.open}–${day.hours.close}` : 'No collections'}
                        </dd>
                    </div>
                ))}
            </dl>
            {children}
        </div>
    );
}

export function AboutSection({ store, prepMinutes }: { store: StoreInfo; prepMinutes: number | null }) {
    const facts = [
        { big: `${prepMinutes ?? 30} min`, title: 'Ready to collect', text: 'We pick your order fresh from the shelves.' },
        { big: `${currencySymbol()}0`, title: 'Price difference', text: 'The same price as on the shelf, from our tills.' },
        { big: 'Pay', title: 'At the counter', text: 'Card or cash when you collect. Nothing to pay online.' },
    ];

    return (
        <section aria-labelledby="about-title" className="grid gap-4 lg:grid-cols-[1.1fr_2fr]">
            <div className="flex flex-col justify-center gap-3 rounded-[28px] bg-white p-6 sm:p-8">
                <h2 id="about-title" className="font-display text-3xl leading-none font-extrabold tracking-[-0.035em] sm:text-4xl">
                    About {store.name}
                </h2>
                <p className="text-body leading-relaxed">
                    Your local shop, now online. What you see here comes straight from our tills, so the prices and stock are what you would find in
                    the shop today.
                </p>
            </div>
            <ul className="grid gap-4 sm:grid-cols-3">
                {facts.map((fact) => (
                    <li key={fact.title} className="flex flex-col gap-2 rounded-[24px] bg-white p-6">
                        <span className="font-display text-brand text-[40px] leading-none font-extrabold tracking-[-0.04em]">{fact.big}</span>
                        <span className="text-[17px] font-bold">{fact.title}</span>
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
