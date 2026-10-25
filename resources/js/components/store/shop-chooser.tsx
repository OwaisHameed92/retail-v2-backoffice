import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Check, ChevronDown, Loader2, Store } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { type StoreInfo } from './types';

/**
 * "Collect from Leeds · Change" (OS-8, audit OSA-34): the visitor's collection shop, changeable from any page (prices
 * and stock follow the shop). Only when the store has more than one shop. A disclosure with a radio list; choosing
 * posts to `shop` and stays on the page. Escape or a click outside closes it.
 */
export function ShopChooser({ store, variant }: { store: StoreInfo; variant: 'bar' | 'pill' }) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);
    const box = useRef<HTMLDivElement>(null);
    const id = useId();
    const shops = store.shops ?? [];

    useEffect(() => {
        if (!open) {
            return;
        }

        const close = (e: MouseEvent | KeyboardEvent) => {
            if (e instanceof KeyboardEvent ? e.key === 'Escape' : !box.current?.contains(e.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', close);

        return () => {
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', close);
        };
    }, [open]);

    if (!store.shop || shops.length < 2 || !store.urls.shop) {
        return null;
    }

    const choose = (shopId: string) => {
        if (shopId === store.shop?.id) {
            setOpen(false);

            return;
        }

        router.post(
            store.urls.shop as string,
            { shopId },
            { preserveScroll: true, onStart: () => setBusy(shopId), onFinish: () => setBusy(null), onSuccess: () => setOpen(false) },
        );
    };

    return (
        <div ref={box} className={cn('relative', variant === 'bar' && 'w-full')}>
            <button
                type="button"
                aria-expanded={open}
                aria-controls={id}
                onClick={() => setOpen((o) => !o)}
                className={cn(
                    'text-ink flex items-center gap-2.5 text-left transition',
                    variant === 'pill'
                        ? 'bg-paper hover:bg-brand-soft h-11 rounded-full py-1 pr-3.5 pl-1'
                        : 'min-h-11 w-full justify-between py-1.5 text-sm',
                )}
            >
                <span className="flex min-w-0 items-center gap-2.5">
                    <span
                        className={cn(
                            'bg-lime text-on-lime flex shrink-0 items-center justify-center rounded-full',
                            variant === 'pill' ? 'size-8' : 'size-7',
                        )}
                    >
                        <Store aria-hidden="true" className="size-[16px]" />
                    </span>
                    <span className="flex min-w-0 flex-col leading-tight">
                        <span className="text-muted text-[11px]">{store.wording?.shopLabel ?? 'Collect from'}</span>
                        <span className="truncate text-sm font-bold">{store.shop.name}</span>
                    </span>
                </span>
                <span className="text-brand-text flex items-center gap-1 text-[13px] font-bold">
                    Change <ChevronDown aria-hidden="true" className={cn('size-4 transition-transform', open && 'rotate-180')} />
                </span>
            </button>
            {open && (
                <div
                    id={id}
                    className={cn(
                        'ring-line absolute z-50 mt-2 flex w-72 max-w-[calc(100vw-2rem)] flex-col gap-1 rounded-3xl bg-white p-2 shadow-2xl ring-1',
                        variant === 'pill' ? 'right-0' : 'left-0',
                    )}
                >
                    <p className="text-muted px-3 pt-2 pb-1 text-xs font-semibold">
                        {store.wording?.shopHint ?? 'Prices and stock follow the shop you collect from.'}
                    </p>
                    <ul
                        role="radiogroup"
                        aria-label={!store.wording || store.wording.mode === 'collection' ? 'Collection shop' : 'Shop'}
                        className="flex flex-col gap-1"
                    >
                        {shops.map((shop) => {
                            const current = shop.id === store.shop?.id;

                            return (
                                <li key={shop.id}>
                                    <button
                                        type="button"
                                        role="radio"
                                        aria-checked={current}
                                        disabled={busy !== null}
                                        onClick={() => choose(shop.id)}
                                        className={cn(
                                            'flex min-h-12 w-full items-center justify-between gap-3 rounded-2xl px-3 text-left font-semibold transition',
                                            current ? 'bg-brand-soft text-ink' : 'hover:bg-paper',
                                        )}
                                    >
                                        {shop.name}
                                        {busy === shop.id ? (
                                            <Loader2 aria-hidden="true" className="size-4 animate-spin" />
                                        ) : (
                                            current && <Check aria-hidden="true" className="text-brand-text size-4" />
                                        )}
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            )}
        </div>
    );
}
