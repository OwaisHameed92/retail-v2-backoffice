import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Loader2, Plus } from 'lucide-react';
import { type ReactNode, useRef } from 'react';
import { defaultQuantity, money, priceLabel } from './format';
import { ProductPhoto } from './product-photo';
import { type StoreProduct } from './types';
import { useAddToBasket, useStore } from './use-store';

/** "500 g · £3.18/kg", "Per kg, weighed in the shop" or "Each"; a deposit return item adds "+ £0.10 deposit" (OS-11). */
export function productSubline(product: StoreProduct): string {
    if (product.isWeighed) {
        return 'Per kg · weighed in the shop';
    }

    const unit = product.unitPrice ? `${money(product.unitPrice.amount)}/${product.unitPrice.unit === 'kg' ? 'kg' : 'litre'}` : null;
    const deposit = product.deposit ? `+ ${money(product.deposit)} deposit` : null;

    return [product.size, unit, deposit].filter(Boolean).join(' · ') || 'Each';
}

/**
 * A product tile (OS-8): a square photo (or the branded placeholder), offer / by weight / out of stock badges, the
 * name (two lines at most, audit OSA-11), pack size and unit price, the price in the store's colour and a round
 * "add" button (1, or 0.5 kg for weighed products).
 */
export function ProductCard({ product, priority = false }: { product: StoreProduct; compact?: boolean; priority?: boolean }) {
    const store = useStore();
    const { add, busy } = useAddToBasket();
    const canAdd = product.orderable && (store?.takingOrders ?? false);
    const adding = busy === product.id;
    const department = store?.departments.find((d) => d.id === product.departmentId)?.name ?? null;

    return (
        <article className="group border-line hover:border-brand-tint relative flex w-full flex-col rounded-2xl border bg-white p-2.5 transition duration-200 hover:shadow-[0_8px_24px_-12px_rgba(14,23,18,0.25)] sm:p-3">
            <div className="pointer-events-none absolute top-4 left-4 z-10 flex max-w-[calc(100%-2rem)] flex-wrap gap-1 sm:top-5 sm:left-5">
                {!product.orderable && <Badge tone="ink">Out of stock</Badge>}
                {product.offer && <Badge tone="sale">{product.offer}</Badge>}
                {product.isWeighed && product.orderable && !product.offer && <Badge tone="highlight">By weight</Badge>}
            </div>
            <Link href={product.url} className="block overflow-hidden rounded-xl" tabIndex={-1} aria-hidden="true">
                <ProductPhoto
                    name={product.name}
                    url={product.photoUrl}
                    alt=""
                    department={department}
                    priority={priority}
                    rounded="rounded-xl"
                    className={cn('transition-transform duration-300 group-hover:scale-[1.03]', !product.orderable && 'opacity-60')}
                />
            </Link>
            <div className="flex flex-1 flex-col gap-0.5 px-0.5 pt-2.5">
                <Link
                    href={product.url}
                    className="text-ink -my-1 py-1 text-sm leading-snug font-semibold underline-offset-2 hover:underline sm:text-[15px]"
                >
                    <span className="line-clamp-2 min-h-[2lh] break-words">{product.name}</span>
                </Link>
                <span className="text-muted line-clamp-1 text-xs">{productSubline(product)}</span>
            </div>
            <div className="mt-2.5 flex items-center justify-between gap-2 px-0.5">
                <span className="text-brand-text text-base font-bold tracking-tight tabular-nums sm:text-[17px]">{priceLabel(product)}</span>
                <button
                    type="button"
                    onClick={() => add(product.id, defaultQuantity(product))}
                    disabled={!canAdd || adding}
                    aria-label={
                        canAdd
                            ? `Add ${product.isWeighed ? '0.5 kg of ' : ''}${product.name} to basket`
                            : `${product.name}: ${product.orderable ? 'not taking orders now' : 'out of stock'}`
                    }
                    className="bg-brand text-on-brand hover:bg-brand-hover disabled:bg-line disabled:text-muted flex size-10 shrink-0 items-center justify-center rounded-full shadow-sm transition active:scale-95 disabled:cursor-not-allowed disabled:shadow-none"
                >
                    {adding ? (
                        <Loader2 aria-hidden="true" className="size-4 animate-spin" />
                    ) : (
                        <Plus aria-hidden="true" className="size-5" strokeWidth={2.4} />
                    )}
                </button>
            </div>
        </article>
    );
}

export function Badge({ tone, children }: { tone: 'ink' | 'sale' | 'highlight' | 'brand'; children: ReactNode }) {
    return (
        <span
            className={cn(
                'max-w-full truncate rounded-md px-2 py-0.5 text-[11px] leading-5 font-semibold',
                tone === 'ink' && 'bg-ink text-white',
                tone === 'sale' && 'bg-sale text-white',
                tone === 'highlight' && 'bg-lime text-on-lime',
                tone === 'brand' && 'bg-brand text-on-brand',
            )}
        >
            {children}
        </span>
    );
}

export function ProductGrid({
    products,
    priority = 0,
    wide = false,
}: {
    products: StoreProduct[];
    compact?: boolean;
    priority?: number;
    wide?: boolean;
}) {
    return (
        <ul className={cn('grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4', wide && 'xl:grid-cols-5')}>
            {products.map((product, i) => (
                <li key={product.id} className="flex">
                    <ProductCard product={product} priority={i < priority} />
                </li>
            ))}
        </ul>
    );
}

/**
 * A shelf of products that scrolls sideways (snap, swipe on phones, arrows on larger screens). Every card stays
 * reachable by keyboard; the arrows only scroll.
 */
export function ProductRail({ products, label }: { products: StoreProduct[]; label: string }) {
    const rail = useRef<HTMLUListElement>(null);
    const scroll = (direction: 1 | -1) => {
        const el = rail.current;

        if (el) {
            const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            el.scrollBy({ left: direction * el.clientWidth * 0.9, behavior: reduce ? 'auto' : 'smooth' });
        }
    };

    return (
        <div className="relative">
            <ul
                ref={rail}
                aria-label={label}
                className="store-rail -mx-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-4 pb-1 sm:-mx-6 sm:gap-4 sm:px-6"
            >
                {products.map((product) => (
                    <li key={product.id} className="flex w-[44%] shrink-0 snap-start sm:w-[30%] lg:w-[calc((100%-4rem)/5)]">
                        <ProductCard product={product} />
                    </li>
                ))}
            </ul>
            {products.length > 5 && (
                <div className="pointer-events-none absolute inset-y-0 -right-4 -left-4 hidden items-center justify-between md:flex">
                    <RailArrow
                        onClick={() => scroll(-1)}
                        label={`Scroll ${label} back`}
                        icon={<ChevronLeft className="size-5" aria-hidden="true" />}
                    />
                    <RailArrow onClick={() => scroll(1)} label={`Scroll ${label} on`} icon={<ChevronRight className="size-5" aria-hidden="true" />} />
                </div>
            )}
        </div>
    );
}

function RailArrow({ onClick, label, icon }: { onClick: () => void; label: string; icon: ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-label={label}
            className="text-ink hover:bg-brand hover:text-on-brand border-line pointer-events-auto flex size-10 items-center justify-center rounded-full border bg-white shadow-md transition"
        >
            {icon}
        </button>
    );
}
