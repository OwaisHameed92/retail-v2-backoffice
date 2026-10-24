import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Loader2, Scale } from 'lucide-react';
import { useState } from 'react';
import { defaultQuantity, lineEstimate, money, priceLabel } from './format';
import { DepartmentPicture, departmentTiles, SectionHeading } from './home-sections';
import { Badge } from './product-card';
import { ProductPhoto } from './product-photo';
import { QtyStepper } from './qty-stepper';
import { COPY, featuredCopy } from './theme';
import { type DepartmentTile, type FeaturedMode, type StoreInfo, type StoreProduct } from './types';
import { useAddToBasket, useStore } from './use-store';
import { WeightChoice } from './weight-choice';

/*
 * Butcher's Block home sections (OS-5, OS-8): meat tiles with photos and the featured cuts with a weight choice and
 * an estimated price. The banners, steps, shops, about, offers and department rows are shared (home-sections.tsx);
 * the template's serif type, cream paper and oxblood colour give the look.
 */

export function MeatTiles({ store, tiles }: { store: StoreInfo; tiles: DepartmentTile[] }) {
    const list = departmentTiles(store, tiles);

    if (list.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="aisles-title" className="flex flex-col gap-4">
            <SectionHeading
                id="aisles-title"
                title={COPY.butcher.departmentsTitle}
                link={{ href: store.urls.search, label: COPY.butcher.allProducts }}
            />
            <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-5">
                {list.map((tile) => (
                    <li key={tile.id} className="flex">
                        <Link
                            href={tile.url}
                            className="group text-ink border-line hover:border-brand-tint flex w-full flex-col gap-3 rounded-2xl border bg-white p-2.5 pb-4 transition duration-200 hover:shadow-md"
                        >
                            <span className="block overflow-hidden rounded-xl">
                                <DepartmentPicture
                                    tile={tile}
                                    className="aspect-[4/3] w-full transition-transform duration-300 group-hover:scale-[1.03]"
                                    iconClass="size-10"
                                />
                            </span>
                            <span className="flex flex-col gap-0.5 px-1.5">
                                <span className="font-display text-[17px] leading-tight font-bold break-words">{tile.name}</span>
                                <span className="text-muted text-xs">
                                    {tile.count} {tile.count === 1 ? 'product' : 'products'}
                                </span>
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}

export function ButcherPicks({ store, products, mode }: { store: StoreInfo; products: StoreProduct[]; mode: FeaturedMode }) {
    if (products.length === 0) {
        return null;
    }

    const copy = featuredCopy('butcher', mode);
    // Hand-picked keep the business's order; otherwise weighed cuts first (the weight choice is for them).
    const shown = (mode === 'handPicked' ? products : [...products].sort((a, b) => Number(b.isWeighed) - Number(a.isWeighed))).slice(0, 8);

    return (
        <section aria-labelledby="featured-title" className="flex flex-col gap-4">
            <SectionHeading id="featured-title" title={copy.title} sub={copy.sub} link={{ href: store.urls.search, label: 'See all' }} />
            <ul className="grid gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4">
                {shown.map((product) => (
                    <li key={product.id} className="flex">
                        <PickCard product={product} takingOrders={store.takingOrders} />
                    </li>
                ))}
            </ul>
        </section>
    );
}

/** One pick: photo, name, price per kg or each, the weight (or quantity), the estimated price and "Add". */
function PickCard({ product, takingOrders }: { product: StoreProduct; takingOrders: boolean }) {
    const { add, busy } = useAddToBasket();
    const store = useStore();
    const [quantity, setQuantity] = useState(defaultQuantity(product));
    const canAdd = product.orderable && takingOrders;
    const adding = busy === product.id;
    const total = lineEstimate(product.price, quantity);
    const department = store?.departments.find((d) => d.id === product.departmentId)?.name ?? null;

    return (
        <article className="group border-line hover:border-brand-tint flex w-full flex-col rounded-2xl border bg-white p-2.5 transition hover:shadow-md sm:p-3">
            <Link href={product.url} tabIndex={-1} aria-hidden="true" className="relative block overflow-hidden rounded-xl">
                <ProductPhoto
                    name={product.name}
                    url={product.photoUrl}
                    alt=""
                    department={department}
                    rounded="rounded-xl"
                    className={cn('aspect-[4/3] transition-transform duration-300 group-hover:scale-[1.03]', !product.orderable && 'opacity-60')}
                />
                <span className="absolute top-2 left-2 flex flex-wrap gap-1">
                    {!product.orderable && <Badge tone="ink">Out of stock</Badge>}
                    {product.offer && <Badge tone="sale">{product.offer}</Badge>}
                </span>
            </Link>
            <div className="flex flex-1 flex-col gap-3 px-0.5 pt-3">
                <div className="flex flex-col gap-0.5">
                    <Link href={product.url} className="font-display text-ink text-[17px] leading-snug font-bold underline-offset-2 hover:underline">
                        <span className="line-clamp-2 break-words">{product.name}</span>
                    </Link>
                    <span className="text-muted text-xs">
                        {product.isWeighed
                            ? `${priceLabel(product)} · weighed at the counter`
                            : `${priceLabel(product)} each${product.size ? ` · ${product.size}` : ''}`}
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
                <div className="mt-auto flex items-end justify-between gap-2">
                    <span className="flex flex-col">
                        {product.isWeighed && <span className="text-muted text-[11px] font-medium">About</span>}
                        <span className="text-brand-text text-lg leading-none font-bold tabular-nums">{money(total)}</span>
                        {product.isWeighed && (
                            <span className="text-muted mt-1 flex items-center gap-1 text-[11px]">
                                <Scale aria-hidden="true" className="size-3" />
                                Final price on the scale
                            </span>
                        )}
                    </span>
                    <button
                        type="button"
                        onClick={() => add(product.id, quantity)}
                        disabled={!canAdd || adding}
                        className="bg-brand text-on-brand hover:bg-brand-hover disabled:bg-line disabled:text-muted flex h-10 items-center justify-center gap-1.5 rounded-full px-4 text-sm font-semibold transition active:scale-95 disabled:cursor-not-allowed"
                    >
                        {adding && <Loader2 aria-hidden="true" className="size-4 animate-spin" />}
                        {canAdd ? 'Add' : product.orderable ? 'Paused' : 'Sold out'}
                        <span className="sr-only">{canAdd ? `: ${product.name} to basket` : `: ${product.name}`}</span>
                    </button>
                </div>
            </div>
        </article>
    );
}
