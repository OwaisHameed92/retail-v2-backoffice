import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ArrowRight, ArrowUpRight } from 'lucide-react';
import { BannerCarousel } from './banner-carousel';
import { departmentIcon } from './department-icon';
import { ProductGrid, ProductRail } from './product-card';
import { COPY, featuredCopy, templateOf } from './theme';
import {
    type BannerSlide,
    type DepartmentRow,
    type DepartmentTile,
    type FeaturedMode,
    type StoreInfo,
    type StoreProduct,
    type StoreTemplate,
} from './types';

export { AboutSection, ClockNote, HowItWorksSection, ShopCard, ShopsSection } from './info-sections';

/*
 * The home page's sections (OS-2, OS-5, OS-8), drawn the same way by both templates (fonts and colours come from the
 * template and the store's colour): banners, departments, featured products, offers, department rows, how it works,
 * shops and about. Butcher's Block swaps a few for its own (butcher-sections.tsx).
 */

export function SectionHeading({ title, sub, link, id }: { title: string; sub?: string; link?: { href: string; label: string }; id?: string }) {
    return (
        <div className="flex items-end justify-between gap-4">
            <div className="flex min-w-0 flex-col gap-1">
                <h2 id={id} className="font-display text-[22px] leading-tight font-bold tracking-[-0.02em] break-words sm:text-[28px]">
                    {title}
                </h2>
                {sub && <p className="text-muted text-sm">{sub}</p>}
            </div>
            {link && (
                <Link
                    href={link.href}
                    className="text-brand-text group inline-flex min-h-11 shrink-0 items-center gap-1 text-sm font-semibold underline-offset-4 hover:underline"
                >
                    {link.label}
                    <ArrowRight aria-hidden="true" className="size-4 transition-transform group-hover:translate-x-0.5" />
                </Link>
            )}
        </div>
    );
}

/** A banner's button: its own link and label, else the template's "Start shopping". */
export function SlideButton({ slide, fallback, label, className }: { slide: BannerSlide; fallback: string; label: string; className: string }) {
    const text = slide.buttonLabel ?? label;
    const icon = slide.external ? <ArrowUpRight aria-hidden="true" className="size-4" /> : <ArrowRight aria-hidden="true" className="size-4" />;

    if (slide.external && slide.href) {
        return (
            <a href={slide.href} target="_blank" rel="noopener noreferrer" className={className}>
                {text}
                {icon}
                <span className="sr-only"> (opens in a new tab)</span>
            </a>
        );
    }

    return (
        <Link href={slide.href ?? fallback} className={className}>
            {text}
            {icon}
        </Link>
    );
}

const BUTTON = 'inline-flex h-11 items-center gap-2 rounded-full px-5 text-[15px] font-semibold transition';

/**
 * The banners as a compact carousel. Fresh Market: the photo fills a rounded frame and the words sit on a soft dark
 * shade. Butcher's Block: words on a cream panel beside the photo (stacked on phones).
 */
export function HeroSection({
    store,
    slides,
    nextCollection,
    prepMinutes,
    howItWorks,
    template,
}: {
    store: StoreInfo;
    slides: BannerSlide[];
    nextCollection: string | null;
    prepMinutes: number | null;
    howItWorks: boolean;
    template: StoreTemplate;
}) {
    const butcher = template === 'butcher';
    const start = store.departments[0]?.url ?? store.urls.search;
    const status = store.takingOrders ? (nextCollection ? `Next collection ${nextCollection}` : 'Click & collect') : 'Browse online, shop in store';
    const defaultText = butcher
        ? `Choose the cut and the weight online. We prepare it for your collection time${prepMinutes ? `, ready in about ${prepMinutes} minutes` : ''}, and you pay the real weight at the counter.`
        : `Order online and collect${store.shop ? ` from ${store.shop.name}` : ''}${prepMinutes ? ` in about ${prepMinutes} minutes` : ''}. Pay at the counter when you pick it up.`;

    return (
        <BannerCarousel
            slides={slides}
            label={`${store.name} offers and news`}
            tone={butcher ? 'light' : 'dark'}
            controlsClassName={butcher ? 'right-4 bottom-4' : 'right-4 bottom-4 sm:right-6 sm:bottom-6'}
            render={(slide, i) => {
                const Heading = i === 0 ? 'h1' : 'h2';
                const image = (
                    <img
                        src={slide.photoUrl}
                        alt=""
                        loading={i === 0 ? 'eager' : 'lazy'}
                        fetchPriority={i === 0 ? 'high' : 'low'}
                        decoding="async"
                        className="absolute inset-0 size-full object-cover"
                    />
                );
                const words = (
                    <>
                        <span
                            className={cn(
                                'flex items-center gap-1.5 self-start rounded-full px-3 py-1 text-xs font-semibold',
                                butcher ? 'bg-lime text-on-lime' : 'bg-white/15 text-white ring-1 ring-white/25 backdrop-blur',
                            )}
                        >
                            <span aria-hidden="true" className={cn('size-1.5 rounded-full', butcher ? 'bg-brand' : 'bg-lime')} />
                            {status}
                        </span>
                        <Heading className="store-hero-title font-display text-[30px] leading-[1.05] font-bold tracking-[-0.025em] text-balance break-words sm:text-[40px] lg:text-[48px]">
                            {slide.headline ?? (butcher ? COPY.butcher.defaultHeadline : COPY.freshMarket.defaultHeadline)}
                        </Heading>
                        <p className={cn('max-w-[460px] text-[15px] leading-relaxed sm:text-base', butcher ? 'text-body' : 'text-white/90')}>
                            {slide.text ?? defaultText}
                        </p>
                        <div className="flex flex-wrap gap-2 pt-1">
                            <SlideButton
                                slide={slide}
                                fallback={start}
                                label={butcher ? COPY.butcher.startShopping : COPY.freshMarket.startShopping}
                                className={cn(
                                    BUTTON,
                                    butcher ? 'bg-brand text-on-brand hover:bg-brand-hover' : 'bg-lime text-on-lime hover:brightness-95',
                                )}
                            />
                            {howItWorks && (
                                <a
                                    href="#how-it-works"
                                    className={cn(
                                        BUTTON,
                                        'hidden sm:inline-flex',
                                        butcher
                                            ? 'bg-paper text-ink hover:bg-brand-soft'
                                            : 'bg-white/15 text-white ring-1 ring-white/30 backdrop-blur hover:bg-white/25',
                                    )}
                                >
                                    How it works
                                </a>
                            )}
                        </div>
                    </>
                );

                return butcher ? (
                    <div className="border-line grid overflow-hidden rounded-2xl border bg-white md:min-h-[400px] md:grid-cols-[1fr_1.1fr]">
                        <div className="bg-tile relative order-first h-[200px] sm:h-[260px] md:order-last md:h-auto">{image}</div>
                        <div className={cn('flex flex-col justify-center gap-3.5 p-6 sm:p-10', slides.length > 1 && 'pb-16 sm:pb-16 md:pb-10')}>
                            {words}
                        </div>
                    </div>
                ) : (
                    <div className="bg-brand-deep relative flex min-h-[380px] overflow-hidden rounded-2xl sm:min-h-[400px] lg:min-h-[420px]">
                        {image}
                        <span
                            aria-hidden="true"
                            className="absolute inset-0 bg-gradient-to-t from-black/75 via-black/35 to-black/5 md:bg-gradient-to-r md:from-black/70 md:via-black/35 md:to-transparent"
                        />
                        <div
                            className={cn(
                                'relative flex flex-col justify-end gap-3.5 p-6 text-white sm:p-10 md:max-w-[620px] md:justify-center',
                                slides.length > 1 && 'pb-16 md:pb-10',
                            )}
                        >
                            {words}
                        </div>
                    </div>
                );
            }}
        />
    );
}

/** A department's picture: a product photo from it, else its icon on the store's tint. */
export function DepartmentPicture({ tile, className, iconClass = 'size-8' }: { tile: DepartmentTile; className?: string; iconClass?: string }) {
    const Icon = departmentIcon(tile.name);

    return tile.photoUrl ? (
        <img src={tile.photoUrl} alt="" loading="lazy" decoding="async" className={cn('bg-white object-contain p-[12%]', className)} />
    ) : (
        <span aria-hidden="true" className={cn('bg-brand-soft text-brand-text flex items-center justify-center', className)}>
            <Icon className={iconClass} strokeWidth={1.6} />
        </span>
    );
}

export function departmentTiles(store: StoreInfo, tiles: DepartmentTile[]): DepartmentTile[] {
    return tiles.length > 0 ? tiles : store.departments.map((d) => ({ ...d, photoUrl: null }));
}

/** Fresh Market: three highlight tiles for the biggest aisles, then every aisle as a round photo. */
export function DepartmentsSection({ store, tiles }: { store: StoreInfo; tiles: DepartmentTile[] }) {
    const list = departmentTiles(store, tiles);

    if (list.length === 0) {
        return null;
    }

    const bento = list.length >= 3 ? [...list].sort((a, b) => b.count - a.count).slice(0, 3) : [];

    return (
        <div className="flex flex-col gap-8 sm:gap-10">
            <section aria-labelledby="aisles-title" className="flex flex-col gap-4">
                <SectionHeading
                    id="aisles-title"
                    title={COPY.freshMarket.departmentsTitle}
                    link={{ href: store.urls.search, label: COPY.freshMarket.allProducts }}
                />
                <ul className="store-rail -mx-4 flex gap-3 overflow-x-auto px-4 sm:-mx-6 sm:px-6 lg:mx-0 lg:grid lg:grid-cols-[repeat(auto-fill,minmax(108px,1fr))] lg:gap-4 lg:overflow-visible lg:px-0">
                    {list.map((tile) => (
                        <li key={tile.id} className="w-[84px] shrink-0 lg:w-auto">
                            <Link href={tile.url} className="group text-ink flex flex-col items-center gap-2 text-center">
                                <span className="border-line group-hover:border-brand-text block aspect-square w-full overflow-hidden rounded-full border bg-white transition group-hover:shadow-md">
                                    <DepartmentPicture
                                        tile={tile}
                                        className="size-full rounded-full transition-transform duration-300 group-hover:scale-105"
                                        iconClass="size-7 lg:size-8"
                                    />
                                </span>
                                <span className="line-clamp-2 text-xs leading-tight font-medium sm:text-[13px]">{tile.name}</span>
                                <span className="sr-only">, {tile.count} products</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </section>
            {bento.length === 3 && (
                <section aria-label="Popular aisles" className="hidden gap-4 sm:grid sm:grid-cols-3">
                    {bento.map((tile, i) => (
                        <BentoTile key={tile.id} tile={tile} tone={(['brand', 'highlight', 'soft'] as const)[i]} />
                    ))}
                </section>
            )}
        </div>
    );
}

function BentoTile({ tile, tone }: { tile: DepartmentTile; tone: 'brand' | 'highlight' | 'soft' }) {
    return (
        <Link
            href={tile.url}
            className={cn(
                'group relative flex min-h-[132px] items-center justify-between gap-3 overflow-hidden rounded-2xl p-5 transition duration-200 hover:-translate-y-0.5 hover:shadow-lg sm:min-h-[160px] sm:p-6',
                tone === 'brand' && 'bg-brand text-on-brand',
                tone === 'highlight' && 'bg-lime text-on-lime',
                tone === 'soft' && 'bg-brand-soft text-ink',
            )}
        >
            <span className="relative z-10 flex min-w-0 flex-col gap-1">
                <span className="text-xs font-medium opacity-80">{tile.count} products</span>
                <span className="font-display text-xl leading-tight font-bold tracking-[-0.02em] break-words sm:text-[22px]">{tile.name}</span>
                <span className="mt-1 inline-flex items-center gap-1 text-sm font-semibold">
                    Shop now <ArrowRight aria-hidden="true" className="size-4 transition-transform group-hover:translate-x-0.5" />
                </span>
            </span>
            <span className="relative size-24 shrink-0 overflow-hidden rounded-full bg-white shadow-sm sm:size-28">
                <DepartmentPicture
                    tile={tile}
                    className="size-full rounded-full transition-transform duration-300 group-hover:scale-105"
                    iconClass="size-10"
                />
            </span>
        </Link>
    );
}

export function FeaturedSection({ store, products, mode }: { store: StoreInfo; products: StoreProduct[]; mode: FeaturedMode }) {
    if (products.length === 0) {
        return null;
    }

    const copy = featuredCopy(templateOf(store.template), mode);

    return (
        <section aria-labelledby="featured-title" className="flex flex-col gap-4">
            <SectionHeading id="featured-title" title={copy.title} sub={copy.sub} link={{ href: store.urls.search, label: 'See all' }} />
            <ProductGrid products={products.slice(0, 10)} wide />
        </section>
    );
}

/** OS-8: products with a till offer on today. */
export function OffersSection({ products, store }: { products: StoreProduct[]; store: StoreInfo }) {
    if (products.length === 0) {
        return null;
    }

    return (
        <section id="offers" aria-labelledby="offers-title" className="flex scroll-mt-24 flex-col gap-4">
            <SectionHeading
                id="offers-title"
                title="On offer"
                sub="Taken off at the till when you collect and pay in the shop"
                link={{ href: store.urls.search, label: 'See all' }}
            />
            <ProductRail products={products} label="Products on offer" />
        </section>
    );
}

/** OS-8: a row of products for each chosen department. */
export function DepartmentRowsSection({ rows }: { rows: DepartmentRow[] }) {
    if (rows.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-10 sm:gap-12">
            {rows.map((row) => (
                <section key={row.id} aria-labelledby={`row-${row.id}`} className="flex flex-col gap-4">
                    <SectionHeading id={`row-${row.id}`} title={row.name} link={{ href: row.url, label: `See all ${row.count}` }} />
                    <ProductRail products={row.products} label={row.name} />
                </section>
            ))}
        </div>
    );
}
