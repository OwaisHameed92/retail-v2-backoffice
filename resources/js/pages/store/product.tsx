import { Breadcrumbs } from '@/components/store/breadcrumbs';
import { defaultQuantity, lineEstimate, money, priceLabel, quantityLabel } from '@/components/store/format';
import { SectionHeading } from '@/components/store/home-sections';
import { Badge, ProductRail, productSubline } from '@/components/store/product-card';
import { ProductPhoto } from '@/components/store/product-photo';
import { QtyStepper } from '@/components/store/qty-stepper';
import { StoreLayout } from '@/components/store/store-layout';
import { type NextSlot, type StoreProduct } from '@/components/store/types';
import { useAddToBasket, useStore } from '@/components/store/use-store';
import { WeightChoice } from '@/components/store/weight-choice';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { CircleAlert, Loader2, Scale, Tag } from 'lucide-react';
import { useState } from 'react';

interface Props {
    product: StoreProduct;
    department: { name: string; url: string } | null;
    /** Ingredients and allergens: the shop's own text, else what its tills hold (audit OSA-3). */
    allergens: string | null;
    inBasket: string;
    nextCollection: string | null;
    /** OS-12: the first slot with room over the shop's methods. */
    nextSlot?: NextSlot | null;
    related: StoreProduct[];
}

/**
 * One product (OS-8): a big photo, price in the store's colour with its pack size and unit price, the till offer,
 * stock at the shop, quantity and "Add to basket", ingredients and allergens before purchase, and more from its
 * department; on phones a sticky add bar.
 */
export default function StoreProductPage({ product, department, allergens, inBasket, nextCollection, nextSlot, related }: Props) {
    const store = useStore();
    const wording = store?.wording;
    const next: NextSlot | null = nextSlot ?? (nextCollection ? { method: 'collection', when: nextCollection } : null);
    const { add, busy } = useAddToBasket();
    const [quantity, setQuantity] = useState(defaultQuantity(product));
    const canAdd = product.orderable && (store?.takingOrders ?? false);
    const total = lineEstimate(product.price, quantity);
    const label = `Add${product.isWeighed ? ' about' : ''} ${money(total)}`;
    const adding = busy === product.id;
    const phone = store?.shop?.phone ?? null;
    const stepper = (tone: 'light' | 'dark', size: 'md' | 'lg') => (
        <QtyStepper
            value={quantity}
            onChange={setQuantity}
            step={product.step}
            min={product.step}
            max={product.max}
            weighed={product.isWeighed}
            name={product.name}
            disabled={!canAdd}
            tone={tone}
            size={size}
        />
    );

    return (
        <StoreLayout title={product.name}>
            <Breadcrumbs
                items={[
                    { label: 'Home', href: store?.urls.home },
                    ...(department ? [{ label: department.name, href: department.url }] : []),
                    { label: product.name },
                ]}
            />
            <div className="flex flex-col gap-12 sm:gap-14">
                <section className="grid items-start gap-6 md:grid-cols-2 md:gap-10 lg:gap-14">
                    <div className="border-line relative overflow-hidden rounded-2xl border bg-white md:sticky md:top-24">
                        <ProductPhoto
                            name={product.name}
                            url={product.photoUrl}
                            rounded="rounded-none"
                            size="lg"
                            department={department?.name}
                            priority
                            sizes="(min-width: 768px) 50vw, 100vw"
                        />
                        <span className="absolute top-4 left-4 flex flex-wrap gap-2">
                            {product.offer && <Badge tone="sale">{product.offer}</Badge>}
                            {product.isWeighed && <Badge tone="highlight">By weight</Badge>}
                        </span>
                    </div>
                    <div className="flex flex-col gap-4">
                        {department && (
                            <Link
                                href={department.url}
                                className="text-brand-text hover:bg-brand-soft inline-flex h-8 items-center self-start rounded-full bg-white px-3 text-xs font-semibold transition"
                            >
                                {department.name}
                            </Link>
                        )}
                        <h1 className="font-display text-[26px] leading-tight font-bold tracking-[-0.02em] break-words sm:text-[32px]">
                            {product.name}
                        </h1>
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span className="text-brand-text text-[28px] leading-none font-bold tracking-[-0.02em] tabular-nums sm:text-[32px]">
                                {priceLabel(product)}
                            </span>
                            <span className="text-muted text-sm">{product.isWeighed ? 'Sold by weight' : productSubline(product)}</span>
                        </div>
                        {product.deposit && (
                            <p className="border-line rounded-xl border bg-white px-4 py-3 text-sm">
                                <strong>Plus {money(product.deposit)} deposit.</strong> The shop adds the deposit when you pay. Return the empty
                                container to the shop to have it refunded.
                            </p>
                        )}
                        {product.offer && (
                            <p className="border-line flex items-start gap-3 rounded-xl border bg-white px-4 py-3 text-sm">
                                <Tag aria-hidden="true" className="text-sale mt-0.5 size-5 shrink-0" />
                                <span>
                                    <strong>In-shop offer: {product.offer}.</strong>{' '}
                                    {wording && wording.mode !== 'collection'
                                        ? 'The till takes it off when the shop rings up your order'
                                        : 'The till takes it off when you collect and pay in the shop'}
                                    ; the basket shows the shelf price.
                                </span>
                            </p>
                        )}
                        <p className="border-line flex items-center gap-2.5 rounded-xl border bg-white px-4 py-3 text-sm font-medium">
                            <span aria-hidden="true" className={cn('size-2.5 shrink-0 rounded-full', product.orderable ? 'bg-ok' : 'bg-sale')} />
                            {product.orderable
                                ? `In stock${store?.shop ? ` at ${store.shop.name}` : ''}${next ? ` · ${next.method === 'delivery' ? 'delivery' : 'collect'} ${next.when}` : ''}`
                                : 'Out of stock at the moment'}
                        </p>
                        {product.isWeighed && (
                            <p className="bg-lime-soft flex items-start gap-3 rounded-xl px-4 py-3 text-sm">
                                <Scale aria-hidden="true" className="mt-0.5 size-5 shrink-0" />
                                <span>
                                    <strong>Estimated price.</strong> Choose the weight in 0.25 kg steps; the shop weighs it and you pay the final{' '}
                                    {wording?.weighedHint ?? 'price on the scale when you collect.'}
                                </span>
                            </p>
                        )}
                        {product.isWeighed && canAdd && (
                            <div className="flex flex-col gap-2">
                                <span className="text-sm font-semibold">Quick weights</span>
                                <WeightChoice product={product} value={quantity} onChange={setQuantity} className="max-w-md" />
                            </div>
                        )}
                        <div className="hidden flex-wrap items-center gap-3 md:flex">
                            {stepper('light', 'lg')}
                            <button
                                type="button"
                                onClick={() => add(product.id, quantity)}
                                disabled={!canAdd || adding}
                                className="bg-brand text-on-brand hover:bg-brand-hover disabled:bg-line disabled:text-muted flex h-14 flex-[1_1_240px] items-center justify-center gap-2 rounded-full px-6 text-base font-semibold shadow-sm transition active:scale-[0.98] disabled:cursor-not-allowed disabled:shadow-none"
                            >
                                {adding && <Loader2 aria-hidden="true" className="size-5 animate-spin" />}
                                {canAdd
                                    ? `Add to basket · ${product.isWeighed ? 'about ' : ''}${money(total)}`
                                    : product.orderable
                                      ? 'Not taking orders'
                                      : 'Out of stock'}
                            </button>
                        </div>
                        {Number(inBasket) > 0 && (
                            <p className="text-body text-sm font-semibold">
                                You have {quantityLabel(inBasket, product.isWeighed)} in your basket.{' '}
                                <Link href={store?.urls.basket ?? '#'} className="text-brand-text underline underline-offset-4">
                                    View basket
                                </Link>
                            </p>
                        )}
                        <div className="grid grid-cols-2 gap-2.5">
                            <div className="bg-brand text-on-brand flex flex-col gap-0.5 rounded-xl p-4">
                                <span className="text-[15px] font-semibold">{next?.when ?? wording?.chip ?? 'Click & collect'}</span>
                                <span className="text-xs opacity-90">
                                    {next ? (wording?.nextHint[next.method] ?? 'Next collection time') : 'Choose a time at checkout'}
                                </span>
                            </div>
                            <div className="bg-lime text-on-lime flex flex-col gap-0.5 rounded-xl p-4">
                                <span className="text-[15px] font-semibold">
                                    {!wording || wording.mode === 'collection' ? 'Pay in the shop' : wording.mode === 'delivery' ? 'Paying' : 'Pay your way'}
                                </span>
                                <span className="text-xs">{wording?.payHint ?? 'Card or cash when you collect'}</span>
                            </div>
                        </div>
                        {product.description && (
                            <div className="border-line flex flex-col gap-1.5 rounded-xl border bg-white p-4">
                                <h2 className="text-[15px] font-semibold">About this product</h2>
                                <p className="text-body text-sm leading-relaxed whitespace-pre-line">{product.description}</p>
                            </div>
                        )}
                        <section aria-labelledby="allergens-title" className="border-line flex flex-col gap-1.5 rounded-xl border bg-white p-4">
                            <h2 id="allergens-title" className="text-[15px] font-semibold">
                                Ingredients &amp; allergens
                            </h2>
                            {allergens && <p className="text-body text-sm leading-relaxed whitespace-pre-line">{allergens}</p>}
                            <p className="text-muted flex items-start gap-2 text-[13px] leading-relaxed">
                                <CircleAlert aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                                <span>
                                    {allergens
                                        ? 'Always check the label before eating. '
                                        : 'We have no ingredient or allergen details for this product online. '}
                                    If you have an allergy, ask the shop before ordering
                                    {phone ? (
                                        <>
                                            {' '}
                                            on{' '}
                                            <a
                                                href={`tel:${phone.replace(/\s+/g, '')}`}
                                                className="text-brand-text font-semibold underline underline-offset-4"
                                            >
                                                {phone}
                                            </a>
                                        </>
                                    ) : null}
                                    .
                                </span>
                            </p>
                        </section>
                    </div>
                </section>

                {related.length > 0 && (
                    <section aria-labelledby="related-title" className="flex flex-col gap-5">
                        <SectionHeading
                            id="related-title"
                            title={department ? `More from ${department.name}` : 'You may also like'}
                            link={department ? { href: department.url, label: 'See all' } : undefined}
                        />
                        <ProductRail products={related} label={department ? `More from ${department.name}` : 'You may also like'} />
                    </section>
                )}
            </div>
            <div aria-hidden="true" className="h-20 md:hidden" />

            {/* Sticky add bar on phones, just above the bottom bar (whose height grows with the home indicator). */}
            <div className="bg-brand-deep fixed inset-x-3 bottom-[calc(4.75rem+env(safe-area-inset-bottom))] z-40 flex items-center gap-1.5 rounded-full p-1.5 shadow-2xl min-[400px]:gap-2 min-[400px]:p-2 md:hidden">
                {stepper('dark', 'md')}
                <button
                    type="button"
                    onClick={() => add(product.id, quantity)}
                    disabled={!canAdd || adding}
                    className="bg-lime text-on-lime flex min-h-12 min-w-0 flex-1 items-center justify-center gap-2 rounded-full px-3 text-[15px] font-semibold whitespace-nowrap disabled:cursor-not-allowed disabled:bg-white/20 disabled:text-white/80 min-[400px]:px-4 min-[400px]:text-base"
                >
                    {adding && <Loader2 aria-hidden="true" className="size-5 animate-spin" />}
                    {canAdd ? label : product.orderable ? 'Not taking orders' : 'Out of stock'}
                </button>
            </div>
        </StoreLayout>
    );
}
