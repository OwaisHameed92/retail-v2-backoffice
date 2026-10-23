import { Breadcrumbs } from '@/components/store/breadcrumbs';
import { defaultQuantity, lineEstimate, money, priceLabel, quantityLabel } from '@/components/store/format';
import { SectionHeading } from '@/components/store/home-sections';
import { ProductGrid } from '@/components/store/product-card';
import { ProductPhoto } from '@/components/store/product-photo';
import { QtyStepper } from '@/components/store/qty-stepper';
import { StoreLayout } from '@/components/store/store-layout';
import { type StoreProduct } from '@/components/store/types';
import { useAddToBasket, useStore } from '@/components/store/use-store';
import { WeightChoice } from '@/components/store/weight-choice';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Loader2, Scale } from 'lucide-react';
import { useState } from 'react';

interface Props {
    product: StoreProduct;
    department: { name: string; url: string } | null;
    inBasket: string;
    nextCollection: string | null;
    related: StoreProduct[];
}

/** One product: photo, price, stock at the shop, quantity and "Add to basket"; on phones a sticky add bar. */
export default function StoreProductPage({ product, department, inBasket, nextCollection, related }: Props) {
    const store = useStore();
    const { add, busy } = useAddToBasket();
    const [quantity, setQuantity] = useState(defaultQuantity(product));
    const canAdd = product.orderable && (store?.takingOrders ?? false);
    const total = lineEstimate(product.price, quantity);
    const label = `Add${product.isWeighed ? ' about' : ''} ${money(total)}`;
    const adding = busy === product.id;
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
            <div className="flex flex-col gap-14">
                <section className="grid items-start gap-6 md:grid-cols-2 md:gap-12">
                    <div className="bg-tile overflow-hidden rounded-[28px] md:rounded-[36px]">
                        <ProductPhoto name={product.name} url={product.photoUrl} rounded="rounded-none" size="lg" />
                    </div>
                    <div className="flex flex-col gap-5">
                        {department && (
                            <Link
                                href={department.url}
                                className="hover:bg-line inline-flex min-h-11 items-center self-start rounded-full bg-white px-4 text-[13px] font-semibold"
                            >
                                {department.name}
                            </Link>
                        )}
                        <h1 className="font-display text-[40px] leading-[0.95] font-extrabold tracking-[-0.045em] break-words sm:text-6xl lg:text-[64px]">
                            {product.name}
                        </h1>
                        <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                            <span className="font-display text-[40px] leading-none font-extrabold tracking-[-0.04em] sm:text-[64px]">
                                {priceLabel(product)}
                            </span>
                            <span className="text-muted">{product.isWeighed ? 'Sold by weight' : 'Each'}</span>
                        </div>
                        <p className="flex items-center gap-3 rounded-[20px] bg-white px-4 py-3.5 text-[15px] font-semibold">
                            <span aria-hidden="true" className={cn('size-3 shrink-0 rounded-full', product.orderable ? 'bg-ok' : 'bg-sale')} />
                            {product.orderable
                                ? `In stock${store?.shop ? ` at ${store.shop.name}` : ''}${nextCollection ? ` · collect ${nextCollection}` : ''}`
                                : 'Out of stock at the moment'}
                        </p>
                        {product.isWeighed && (
                            <p className="bg-lime-soft flex items-start gap-3 rounded-[20px] px-4 py-3.5 text-[15px]">
                                <Scale aria-hidden="true" className="mt-0.5 size-5 shrink-0" />
                                <span>
                                    <strong>Estimated price.</strong> Choose the weight in 0.25 kg steps; the shop weighs it and you pay the final
                                    price on the scale when you collect.
                                </span>
                            </p>
                        )}
                        {product.isWeighed && canAdd && (
                            <div className="flex flex-col gap-2">
                                <span className="text-sm font-bold">Quick weights</span>
                                <WeightChoice product={product} value={quantity} onChange={setQuantity} className="max-w-md" />
                            </div>
                        )}
                        <div className="hidden flex-wrap items-center gap-3 md:flex">
                            {stepper('light', 'lg')}
                            <button
                                type="button"
                                onClick={() => add(product.id, quantity)}
                                disabled={!canAdd || adding}
                                className="bg-brand text-on-brand disabled:bg-line disabled:text-muted flex min-h-14 flex-[1_1_240px] items-center justify-center gap-2 rounded-full px-6 text-[17px] font-extrabold transition hover:brightness-110 disabled:cursor-not-allowed"
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
                                <Link href={store?.urls.basket ?? '#'} className="text-brand underline underline-offset-4">
                                    View basket
                                </Link>
                            </p>
                        )}
                        <div className="grid grid-cols-2 gap-2.5">
                            <div className="bg-ink flex flex-col gap-1 rounded-[22px] p-4 text-white sm:p-5">
                                <span className="font-display text-lime text-lg font-extrabold sm:text-[22px]">
                                    {nextCollection ?? 'Click & collect'}
                                </span>
                                <span className="text-sm">{nextCollection ? 'Next collection time' : 'Choose a time at checkout'}</span>
                            </div>
                            <div className="bg-lime flex flex-col gap-1 rounded-[22px] p-4 sm:p-5">
                                <span className="font-display text-lg font-extrabold sm:text-[22px]">Pay in the shop</span>
                                <span className="text-sm">Card or cash when you collect</span>
                            </div>
                        </div>
                        {product.description && (
                            <div className="flex flex-col gap-2 rounded-[22px] bg-white px-5 py-5">
                                <h2 className="font-bold">About this product</h2>
                                <p className="text-body leading-relaxed whitespace-pre-line">{product.description}</p>
                            </div>
                        )}
                    </div>
                </section>

                {related.length > 0 && (
                    <section aria-labelledby="related-title" className="flex flex-col gap-5">
                        <SectionHeading id="related-title" title={department ? `More from ${department.name}` : 'You may also like'} />
                        <ProductGrid products={related} compact />
                    </section>
                )}
            </div>
            <div aria-hidden="true" className="h-20 md:hidden" />

            {/* Sticky add bar on phones, just above the bottom bar (whose height grows with the home indicator). */}
            <div className="bg-ink fixed inset-x-3 bottom-[calc(4.75rem+env(safe-area-inset-bottom))] z-40 flex items-center gap-1.5 rounded-full p-1.5 shadow-2xl min-[400px]:gap-2 min-[400px]:p-2 md:hidden">
                {stepper('dark', 'md')}
                <button
                    type="button"
                    onClick={() => add(product.id, quantity)}
                    disabled={!canAdd || adding}
                    className="bg-lime text-ink flex min-h-12 min-w-0 flex-1 items-center justify-center gap-2 rounded-full px-3 text-[15px] font-extrabold whitespace-nowrap disabled:cursor-not-allowed disabled:bg-white/20 disabled:text-white/80 min-[400px]:px-4 min-[400px]:text-base"
                >
                    {adding && <Loader2 aria-hidden="true" className="size-5 animate-spin" />}
                    {canAdd ? label : product.orderable ? 'Not taking orders' : 'Out of stock'}
                </button>
            </div>
        </StoreLayout>
    );
}
