import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ArrowRight, Loader2, Scale } from 'lucide-react';
import { useState } from 'react';
import { defaultQuantity, initialOf, lineEstimate, money, priceLabel, tintFor } from './format';
import { SectionHeading } from './home-sections';
import { ProductPhoto } from './product-photo';
import { QtyStepper } from './qty-stepper';
import { COPY } from './theme';
import { type StoreInfo, type StoreProduct } from './types';
import { useAddToBasket } from './use-store';
import { WeightChoice } from './weight-choice';

/*
 * Butcher's Block home sections (OS-5, owner-approved mockup "12 · Home — Butcher's Block"): a white headline card
 * beside a rounded photo frame, meat tiles, "Butcher's picks" with a weight choice and an estimated price, and the
 * three steps. Light only. The shops and about sections are shared with Fresh Market (home-sections.tsx).
 */

export function ButcherHero({
    store,
    headline,
    bannerUrl,
    nextCollection,
    prepMinutes,
    pick,
    howItWorks,
}: {
    store: StoreInfo;
    headline: string | null;
    bannerUrl: string;
    nextCollection: string | null;
    prepMinutes: number | null;
    pick: StoreProduct | null;
    howItWorks: boolean;
}) {
    const start = store.departments[0]?.url ?? store.urls.search;
    const chips = ['Pay for the real weight', 'Pay when you collect', 'No account needed'];

    return (
        <section aria-labelledby="hero-title" className="grid gap-3 md:grid-cols-2 md:gap-5">
            <div className="flex flex-col justify-center gap-5 rounded-[30px] bg-white p-6 sm:p-10 md:min-h-[560px] md:gap-6 md:rounded-[36px] lg:p-14">
                <span className="bg-lime text-ink self-start rounded-full px-3.5 py-2 text-xs font-bold tracking-[0.14em] uppercase">
                    {store.takingOrders
                        ? nextCollection
                            ? `Next collection ${nextCollection}`
                            : 'Click & collect'
                        : 'Browse online, buy in the shop'}
                </span>
                <h1
                    id="hero-title"
                    className="font-display text-[44px] leading-[0.92] font-extrabold tracking-[-0.04em] text-balance break-words sm:text-6xl lg:text-[84px]"
                >
                    {headline ?? (
                        <>
                            {COPY.butcher.defaultHeadline}
                            <br />
                            <span className="text-brand font-semibold italic">Ready to collect.</span>
                        </>
                    )}
                </h1>
                <p className="text-body max-w-[460px] text-base leading-relaxed sm:text-[19px]">
                    Choose the cut and the weight online. We prepare it for your collection time
                    {store.shop ? ` at ${store.shop.name}` : ''}
                    {prepMinutes ? `, ready in about ${prepMinutes} minutes` : ''}, and you pay for the real weight at the counter.
                </p>
                <div className="flex flex-wrap gap-3">
                    <Link
                        href={start}
                        className="bg-brand text-on-brand inline-flex min-h-12 items-center gap-2.5 rounded-full px-6 font-extrabold transition hover:brightness-110 sm:min-h-[60px] sm:px-8"
                    >
                        {COPY.butcher.startShopping}
                        <ArrowRight aria-hidden="true" className="size-[18px]" strokeWidth={2.4} />
                    </Link>
                    {howItWorks && (
                        <a
                            href="#how-it-works"
                            className="bg-paper text-ink hover:bg-line inline-flex min-h-12 items-center rounded-full px-6 font-bold transition sm:min-h-[60px]"
                        >
                            How it works
                        </a>
                    )}
                </div>
                <ul className="flex flex-wrap gap-2">
                    {chips.map((chip) => (
                        <li key={chip} className="bg-paper rounded-full px-3 py-1.5 text-[13px] font-semibold">
                            {chip}
                        </li>
                    ))}
                </ul>
            </div>
            <div className="bg-tile relative min-h-[280px] overflow-hidden rounded-[30px] sm:min-h-[400px] md:min-h-[560px] md:rounded-[36px]">
                <img src={bannerUrl} alt="" className="absolute inset-0 size-full object-cover object-[75%_50%]" />
                {pick && (
                    <Link
                        href={pick.url}
                        className="absolute right-3 bottom-3 left-3 flex flex-col gap-0.5 rounded-[22px] bg-white px-5 py-4 transition hover:brightness-95 sm:right-auto sm:bottom-5 sm:left-5 sm:max-w-[320px]"
                    >
                        <span className="text-muted text-xs font-bold tracking-[0.12em] uppercase">Butcher's pick</span>
                        <span className="font-display truncate text-[22px] leading-tight font-extrabold">{pick.name}</span>
                        <span className="text-body text-sm">{priceLabel(pick)}</span>
                    </Link>
                )}
            </div>
        </section>
    );
}

export function MeatTiles({ store }: { store: StoreInfo }) {
    if (store.departments.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="aisles-title" className="flex flex-col gap-5 sm:gap-6">
            <SectionHeading
                id="aisles-title"
                title={COPY.butcher.departmentsTitle}
                link={{ href: store.urls.search, label: COPY.butcher.allProducts }}
            />
            <ul className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 sm:gap-4 lg:grid-cols-5">
                {store.departments.map((department) => (
                    <li key={department.id} className="flex">
                        <Link
                            href={department.url}
                            className="group text-ink flex w-full flex-col gap-3 rounded-[24px] bg-white p-2 pb-4 sm:rounded-[28px] sm:p-2.5 sm:pb-5"
                        >
                            <span
                                aria-hidden="true"
                                className="font-display text-ink/60 flex aspect-[4/3] w-full items-center justify-center rounded-[18px] text-4xl font-extrabold transition-transform group-hover:scale-[1.02] sm:aspect-square sm:rounded-[20px] sm:text-6xl"
                                style={{ backgroundColor: tintFor(department.name, 'butcher') }}
                            >
                                {initialOf(department.name)}
                            </span>
                            <span className="flex flex-col gap-0.5 px-2">
                                <span className="font-display text-xl leading-tight font-extrabold tracking-[-0.02em] break-words sm:text-2xl">
                                    {department.name}
                                </span>
                                <span className="text-muted text-sm">
                                    {department.count} {department.count === 1 ? 'product' : 'products'}
                                </span>
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}

export function ButcherPicks({ store, products }: { store: StoreInfo; products: StoreProduct[] }) {
    if (products.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="featured-title" className="flex flex-col gap-5 sm:gap-6">
            <SectionHeading
                id="featured-title"
                title={COPY.butcher.featuredTitle}
                sub={COPY.butcher.featuredSub}
                link={{ href: store.urls.search, label: 'See all' }}
            />
            <ul className="grid gap-3 sm:grid-cols-2 sm:gap-5 lg:grid-cols-3">
                {/* Weighed cuts first: they are what the weight choice is for. */}
                {[...products]
                    .sort((a, b) => Number(b.isWeighed) - Number(a.isWeighed))
                    .slice(0, 6)
                    .map((product) => (
                        <li key={product.id} className="flex">
                            <PickCard product={product} takingOrders={store.takingOrders} />
                        </li>
                    ))}
            </ul>
        </section>
    );
}

/** One pick: photo, name, price per kg or each, the weight (or quantity), the estimated price and "Add to basket". */
function PickCard({ product, takingOrders }: { product: StoreProduct; takingOrders: boolean }) {
    const { add, busy } = useAddToBasket();
    const [quantity, setQuantity] = useState(defaultQuantity(product));
    const canAdd = product.orderable && takingOrders;
    const adding = busy === product.id;
    const total = lineEstimate(product.price, quantity);

    return (
        <article className={cn('flex w-full flex-col rounded-[28px] bg-white p-2 sm:rounded-[30px] sm:p-2.5', !product.orderable && 'opacity-80')}>
            <Link href={product.url} tabIndex={-1} aria-hidden="true" className="relative block overflow-hidden rounded-[20px] sm:rounded-[22px]">
                <ProductPhoto name={product.name} url={product.photoUrl} alt="" rounded="rounded-none" className="aspect-[4/3]" />
                {!product.orderable && (
                    <span className="bg-ink absolute top-3 left-3 rounded-full px-3 py-1.5 text-xs font-bold text-white">Out of stock</span>
                )}
            </Link>
            <div className="flex flex-1 flex-col gap-4 px-3 pt-4 pb-3">
                <div className="flex flex-col gap-1">
                    <Link
                        href={product.url}
                        className="font-display text-ink flex min-h-11 items-center text-[22px] leading-tight font-bold tracking-[-0.01em] underline-offset-4 hover:underline sm:text-[26px]"
                    >
                        {product.name}
                    </Link>
                    <span className="text-muted text-sm">
                        {product.isWeighed ? `${priceLabel(product)} · weighed at the counter` : `${priceLabel(product)} each`}
                    </span>
                </div>
                {product.isWeighed ? (
                    <WeightChoice product={product} value={quantity} onChange={setQuantity} disabled={!canAdd} />
                ) : (
                    <div className="self-start">
                        <QtyStepper
                            value={quantity}
                            onChange={setQuantity}
                            step={product.step}
                            min={product.step}
                            max={product.max}
                            weighed={false}
                            name={product.name}
                            disabled={!canAdd}
                            tone="paper"
                        />
                    </div>
                )}
                <div className="mt-auto flex flex-wrap items-end justify-between gap-3">
                    <span className="flex flex-col">
                        {product.isWeighed && <span className="text-muted text-xs font-semibold">About</span>}
                        <span className="font-display text-[30px] leading-none font-extrabold tracking-[-0.02em] sm:text-[34px]">{money(total)}</span>
                        {product.isWeighed && (
                            <span className="text-muted mt-1 flex items-center gap-1 text-xs">
                                <Scale aria-hidden="true" className="size-3.5" />
                                Final price on the scale
                            </span>
                        )}
                    </span>
                    <button
                        type="button"
                        onClick={() => add(product.id, quantity)}
                        disabled={!canAdd || adding}
                        className="bg-brand text-on-brand disabled:bg-line disabled:text-muted flex min-h-[52px] items-center justify-center gap-2 rounded-full px-6 font-extrabold transition hover:brightness-110 disabled:cursor-not-allowed"
                    >
                        {adding && <Loader2 aria-hidden="true" className="size-5 animate-spin" />}
                        {canAdd ? 'Add to basket' : product.orderable ? 'Not taking orders' : 'Out of stock'}
                        {canAdd && <span className="sr-only">: {product.name}</span>}
                    </button>
                </div>
            </div>
        </article>
    );
}

export function ButcherSteps({ prepMinutes }: { prepMinutes: number | null }) {
    const steps = [
        { n: '01', title: 'Choose your cut', text: 'Pick the meat and the weight. Weighed cuts show an estimated price.' },
        {
            n: '02',
            title: 'We prepare it fresh',
            text: `Our butchers cut and pack it for your collection time. We need about ${prepMinutes ?? 30} minutes.`,
        },
        { n: '03', title: 'Collect and pay', text: 'We weigh it at the counter and you pay the real weight, by card or cash.' },
    ];

    return (
        <section id="how-it-works" aria-labelledby="how-title" className="flex scroll-mt-6 flex-col gap-5 sm:gap-6">
            <SectionHeading id="how-title" title="How it works" sub="Click & collect: order online, pay at the counter" />
            <ol className="grid gap-3 sm:gap-4 md:grid-cols-3">
                {steps.map((step) => (
                    <li key={step.n} className="flex flex-col gap-2.5 rounded-[28px] bg-white p-6 sm:p-7">
                        <span aria-hidden="true" className="font-display text-brand text-5xl leading-none font-extrabold sm:text-[56px]">
                            {step.n}
                        </span>
                        <span className="font-display text-2xl font-bold">{step.title}</span>
                        <span className="text-body leading-relaxed">{step.text}</span>
                    </li>
                ))}
            </ol>
        </section>
    );
}
