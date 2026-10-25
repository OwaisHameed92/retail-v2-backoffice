import { cn } from '@/lib/utils';
import { Link, router, usePage } from '@inertiajs/react';
import { Search, ShoppingBag } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { money } from './format';
import { ShopChooser } from './shop-chooser';
import { useTemplate } from './theme';
import { type BasketSummary, type StoreInfo } from './types';

/**
 * The store's mark: its uploaded logo alone (the name is the link's accessible text), sized for a header; without a
 * logo, its initials on the brand colour and the name beside them.
 */
export function StoreLogo({ store, size = 'md', withName = true }: { store: StoreInfo; size?: 'sm' | 'md'; withName?: boolean }) {
    if (store.logoUrl) {
        return (
            <img
                src={store.logoUrl}
                alt=""
                className={cn('w-auto shrink-0 object-contain', size === 'sm' ? 'h-9 max-w-[140px]' : 'h-8 max-w-[150px] sm:h-10 sm:max-w-[190px]')}
            />
        );
    }

    return (
        <span className="flex min-w-0 items-center gap-2.5">
            <span
                aria-hidden="true"
                className={cn(
                    'bg-brand text-on-brand font-display flex shrink-0 items-center justify-center rounded-xl font-bold',
                    size === 'sm' ? 'size-9 text-sm' : 'size-9 text-sm sm:size-10 sm:text-base',
                )}
            >
                {store.initials}
            </span>
            {withName && <span className="font-display truncate text-lg leading-none font-bold tracking-[-0.01em] sm:text-xl">{store.name}</span>}
        </span>
    );
}

/**
 * The store's header (OS-8): a thin strip in the store's colour (how it gets orders to you, OS-12: collect, delivery
 * or both; open now), then a slim bar with the
 * logo, search, the collection shop (changeable on every screen size, audit OSA-34) and the basket, sticky on larger
 * screens; then the departments as chips (a swipeable row on phones), the current one in the store's colour.
 */
export function StoreHeader({ store, basket }: { store: StoreInfo; basket?: BasketSummary }) {
    const butcher = useTemplate() === 'butcher';
    const { url } = usePage();
    const current = new URL(url, 'https://store.local');
    const [term, setTerm] = useState(current.pathname === store.urls.search ? (current.searchParams.get('q') ?? '') : '');
    const count = basket?.count ?? 0;
    const choosesShop = (store.shops?.length ?? 0) > 1;

    const search = (event: FormEvent) => {
        event.preventDefault();
        router.get(store.urls.search, term.trim() === '' ? {} : { q: term.trim() });
    };

    return (
        <>
            <div className="bg-brand text-on-brand text-xs">
                <div className="mx-auto flex h-9 max-w-[1280px] items-center justify-between gap-6 px-4 sm:px-6">
                    <p className="flex min-w-0 items-center gap-2">
                        <span className="bg-lime text-on-lime shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold">
                            {store.wording?.chip ?? 'Click & collect'}
                        </span>
                        <span className="min-w-0 truncate">
                            {store.wording?.strip ?? `Order online, pay when you collect${store.shop ? ` at ${store.shop.name}` : ''}`}
                        </span>
                    </p>
                    <p className="hidden shrink-0 items-center gap-5 sm:flex">
                        {store.shop?.openNow && (
                            <span className="flex items-center gap-1.5 font-medium">
                                <span
                                    aria-hidden="true"
                                    className={cn('size-1.5 rounded-full', store.shop.openNow.open ? 'bg-lime' : 'bg-white/50')}
                                />
                                {store.shop.openNow.label}
                            </span>
                        )}
                        <span className="hidden opacity-85 lg:inline">
                            {butcher ? 'Weighed at the counter: you pay the real weight' : 'Same prices as on the shelf'}
                        </span>
                    </p>
                </div>
            </div>
            <header className="border-line z-40 border-b bg-white/95 backdrop-blur supports-[backdrop-filter]:bg-white/90 md:sticky md:top-0">
                <div className="mx-auto flex max-w-[1280px] flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3 sm:px-6 md:h-[68px] md:flex-nowrap md:py-0">
                    <Link
                        href={store.urls.home}
                        className="text-ink flex min-h-11 min-w-0 flex-1 items-center md:flex-none"
                        aria-label={`${store.name}, home`}
                    >
                        <StoreLogo store={store} />
                    </Link>

                    <form
                        role="search"
                        onSubmit={search}
                        className="bg-paper focus-within:ring-brand-text order-3 flex h-11 w-full items-center gap-2 rounded-full pr-1 pl-4 transition focus-within:bg-white focus-within:ring-2 md:order-none md:max-w-[560px] md:flex-1"
                    >
                        <Search aria-hidden="true" className="text-muted size-[18px] shrink-0" />
                        <label htmlFor="store-search" className="sr-only">
                            Search the shop
                        </label>
                        <input
                            id="store-search"
                            type="search"
                            value={term}
                            onChange={(e) => setTerm(e.target.value)}
                            placeholder={butcher ? 'Search cuts' : 'Search products'}
                            autoComplete="off"
                            enterKeyHint="search"
                            className="placeholder:text-muted h-full min-w-0 flex-1 bg-transparent text-base outline-none focus-visible:outline-none sm:text-sm"
                        />
                        <button
                            type="submit"
                            className="bg-brand text-on-brand hover:bg-brand-hover hidden h-9 rounded-full px-4 text-sm font-semibold transition sm:block"
                        >
                            Search
                        </button>
                    </form>

                    <div className="flex shrink-0 items-center gap-2 md:ml-auto">
                        {choosesShop && (
                            <div className="hidden lg:block">
                                <ShopChooser store={store} variant="pill" />
                            </div>
                        )}
                        <Link
                            href={store.urls.basket}
                            className="bg-brand text-on-brand hover:bg-brand-hover flex h-11 items-center gap-2 rounded-full pr-1.5 pl-3.5 text-sm font-semibold transition"
                            aria-label={`Basket: ${count} ${count === 1 ? 'item' : 'items'}, ${money(basket?.total ?? '0')}`}
                        >
                            <ShoppingBag aria-hidden="true" className="size-[18px]" />
                            <span className="hidden tabular-nums sm:inline">{money(basket?.total ?? '0')}</span>
                            <span className="bg-lime text-on-lime flex h-8 min-w-8 items-center justify-center rounded-full px-1.5 text-xs font-bold tabular-nums">
                                {count > 99 ? '99+' : count}
                            </span>
                        </Link>
                    </div>
                </div>
                {choosesShop && (
                    <div className="border-line border-t px-4 lg:hidden">
                        <ShopChooser store={store} variant="bar" />
                    </div>
                )}
            </header>

            {store.departments.length > 0 && (
                <nav aria-label="Departments" className="border-line border-b bg-white">
                    <ul className="store-rail mx-auto flex max-w-[1280px] gap-1.5 overflow-x-auto px-4 py-2.5 sm:px-6">
                        {store.departments.map((department) => {
                            const active = current.pathname === department.url;

                            return (
                                <li key={department.id} className="shrink-0">
                                    <Link
                                        href={department.url}
                                        aria-current={active ? 'page' : undefined}
                                        className={cn(
                                            'text-body hover:border-brand-text hover:text-ink border-line flex h-9 items-center rounded-full border px-3.5 text-[13px] font-medium whitespace-nowrap transition-colors',
                                            active && 'bg-brand text-on-brand hover:text-on-brand border-transparent',
                                        )}
                                    >
                                        {department.name}
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </nav>
            )}
        </>
    );
}
