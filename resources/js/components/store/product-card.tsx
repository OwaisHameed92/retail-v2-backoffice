import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Loader2, Plus } from 'lucide-react';
import { defaultQuantity, priceLabel } from './format';
import { ProductPhoto } from './product-photo';
import { type StoreProduct } from './types';
import { useAddToBasket, useStore } from './use-store';

/** A product tile (Fresh Market): photo, name, price and a quick "add" (1, or 0.5 kg for weighed products). */
export function ProductCard({ product, compact = false }: { product: StoreProduct; compact?: boolean }) {
    const store = useStore();
    const { add, busy } = useAddToBasket();
    const canAdd = product.orderable && (store?.takingOrders ?? false);
    const adding = busy === product.id;

    return (
        <article
            className={cn(
                'group relative flex w-full flex-col bg-white',
                compact ? 'gap-2 rounded-[22px] p-1.5 pb-3' : 'gap-2.5 rounded-[22px] p-1.5 pb-3 sm:gap-3 sm:rounded-[28px] sm:p-2.5 sm:pb-4',
                !product.orderable && 'opacity-80',
            )}
        >
            {!product.orderable && (
                <span className="bg-ink absolute top-3 left-3 z-10 rounded-full px-2.5 py-1 text-xs font-bold text-white sm:top-4 sm:left-4 sm:px-3 sm:py-1.5">
                    Out of stock
                </span>
            )}
            {product.isWeighed && product.orderable && (
                <span className="bg-lime text-ink absolute top-3 left-3 z-10 rounded-full px-2.5 py-1 text-xs font-bold sm:top-4 sm:left-4 sm:px-3 sm:py-1.5">
                    By weight
                </span>
            )}
            <Link href={product.url} className="block overflow-hidden rounded-[20px]" tabIndex={-1} aria-hidden="true">
                <ProductPhoto
                    name={product.name}
                    url={product.photoUrl}
                    alt=""
                    className="transition-transform duration-300 group-hover:scale-[1.03]"
                />
            </Link>
            <div className={cn('flex flex-col gap-1', compact ? 'px-1.5' : 'px-2')}>
                <Link
                    href={product.url}
                    className={cn(
                        // The padding (offset by the margin) makes the name a 44px tap target without moving it.
                        'text-ink -my-2.5 line-clamp-2 py-2.5 leading-snug font-bold underline-offset-4 hover:underline',
                        compact ? 'text-sm' : 'text-base',
                    )}
                >
                    {product.name}
                </Link>
                <span className="text-muted text-xs sm:text-[13px]">{product.isWeighed ? 'Per kg, weighed in the shop' : 'Each'}</span>
            </div>
            <div className={cn('mt-auto flex items-center justify-between gap-2', compact ? 'pr-0.5 pl-1.5' : 'pr-1 pl-2')}>
                <span
                    className={cn(
                        'font-display min-w-0 leading-none font-extrabold tracking-tight',
                        compact ? 'text-lg sm:text-xl' : 'text-lg sm:text-[26px]',
                    )}
                >
                    {priceLabel(product)}
                </span>
                <button
                    type="button"
                    onClick={() => add(product.id, defaultQuantity(product))}
                    disabled={!canAdd || adding}
                    aria-label={
                        canAdd
                            ? `Add ${product.isWeighed ? '0.5 kg of ' : ''}${product.name} to basket`
                            : `${product.name}: ${product.orderable ? 'not taking orders now' : 'out of stock'}`
                    }
                    className={cn(
                        'bg-ink text-lime hover:bg-brand hover:text-on-brand disabled:bg-line disabled:text-muted flex shrink-0 items-center justify-center rounded-full transition disabled:cursor-not-allowed',
                        compact ? 'size-11' : 'size-11 sm:size-12',
                    )}
                >
                    {adding ? (
                        <Loader2 aria-hidden="true" className="size-5 animate-spin" />
                    ) : (
                        <Plus aria-hidden="true" className="size-6" strokeWidth={2.6} />
                    )}
                </button>
            </div>
        </article>
    );
}

export function ProductGrid({ products, compact = false }: { products: StoreProduct[]; compact?: boolean }) {
    return (
        <ul className={cn('grid gap-2.5 sm:gap-4', compact ? 'grid-cols-2 lg:grid-cols-4' : 'grid-cols-2 md:grid-cols-3 xl:grid-cols-4')}>
            {products.map((product) => (
                <li key={product.id} className="flex">
                    <div className="flex w-full">
                        <ProductCard product={product} compact={compact} />
                    </div>
                </li>
            ))}
        </ul>
    );
}
