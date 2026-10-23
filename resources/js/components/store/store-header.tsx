import { cn } from '@/lib/utils';
import { Link, router, usePage } from '@inertiajs/react';
import { Search, ShoppingBag, Store } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { money } from './format';
import { useTemplate } from './theme';
import { type BasketSummary, type StoreInfo } from './types';

/** The store's logo: its uploaded logo, else its initials on the brand colour. */
export function StoreLogo({ store, size = 'md' }: { store: StoreInfo; size?: 'sm' | 'md' }) {
    const box = size === 'sm' ? 'size-10 rounded-[13px] text-base' : 'size-11 rounded-[14px] text-lg sm:size-12 sm:rounded-2xl sm:text-xl';

    if (store.logoUrl) {
        return <img src={store.logoUrl} alt="" className={cn('shrink-0 bg-white object-contain', box)} />;
    }

    return (
        <span aria-hidden="true" className={cn('bg-brand font-display text-lime flex shrink-0 items-center justify-center font-extrabold', box)}>
            {store.initials}
        </span>
    );
}

/**
 * The store's header: the click & collect bar (ink for Fresh Market, the brand colour for the butcher), logo and
 * name, search, collection shop and basket, then the departments (a swipeable row on phones).
 */
export function StoreHeader({ store, basket }: { store: StoreInfo; basket?: BasketSummary }) {
    const butcher = useTemplate() === 'butcher';
    const { url } = usePage();
    const current = new URL(url, 'https://store.local');
    const [term, setTerm] = useState(current.pathname === store.urls.search ? (current.searchParams.get('q') ?? '') : '');

    const search = (event: FormEvent) => {
        event.preventDefault();
        router.get(store.urls.search, term.trim() === '' ? {} : { q: term.trim() });
    };

    return (
        <>
            <div className={cn('text-[13px]', butcher ? 'bg-brand text-on-brand' : 'bg-ink text-white')}>
                <div className="mx-auto flex max-w-[1320px] flex-wrap items-center justify-between gap-x-6 gap-y-1 px-4 py-2.5 sm:px-6">
                    <p className="flex min-w-0 items-center gap-2.5">
                        <span className="bg-lime text-ink shrink-0 rounded-full px-2.5 py-0.5 font-bold">Click &amp; collect</span>
                        <span className="min-w-0">Order online, pay when you collect{store.shop ? ` at ${store.shop.name}` : ''}.</span>
                    </p>
                    <p className="hidden opacity-85 sm:block">
                        {butcher ? 'Weighed at the counter, so you pay the real weight' : 'Same prices as on the shelf, live from our tills'}
                    </p>
                </div>
            </div>
            <header className="border-line border-b bg-white">
                <div className="mx-auto flex max-w-[1320px] flex-wrap items-center gap-x-5 gap-y-3 px-4 py-3.5 sm:px-6 sm:py-4">
                    <Link
                        href={store.urls.home}
                        className="text-ink flex min-h-11 min-w-0 flex-1 items-center gap-3 md:flex-none"
                        aria-label={`${store.name}, home`}
                    >
                        <StoreLogo store={store} />
                        <span
                            className={cn(
                                'font-display truncate text-xl leading-none font-extrabold',
                                butcher ? 'tracking-[-0.02em] sm:text-[28px]' : 'tracking-[-0.03em] sm:text-[24px]',
                            )}
                        >
                            {store.name}
                        </span>
                    </Link>

                    <form
                        role="search"
                        onSubmit={search}
                        className="bg-paper order-3 flex min-h-[52px] w-full flex-[1_1_360px] items-center gap-2.5 rounded-full pr-1.5 pl-5 md:order-none md:w-auto"
                    >
                        <Search aria-hidden="true" className="text-muted size-5 shrink-0" />
                        <label htmlFor="store-search" className="sr-only">
                            Search the shop
                        </label>
                        <input
                            id="store-search"
                            type="search"
                            value={term}
                            onChange={(e) => setTerm(e.target.value)}
                            placeholder="Search products"
                            autoComplete="off"
                            enterKeyHint="search"
                            className="placeholder:text-muted min-h-11 min-w-0 flex-1 bg-transparent text-base outline-none sm:text-[15px]"
                        />
                        <button type="submit" className="bg-ink hover:bg-brand hidden min-h-11 rounded-full px-5 font-semibold text-white sm:block">
                            Search
                        </button>
                    </form>

                    <div className="flex shrink-0 items-center gap-2.5">
                        {store.shop && store.shopCount > 1 && (
                            <Link
                                href={store.urls.checkout}
                                className="bg-paper text-ink hidden min-h-12 items-center gap-2.5 rounded-full py-1.5 pr-4 pl-1.5 lg:flex"
                            >
                                <span className="bg-lime flex size-9 items-center justify-center rounded-full">
                                    <Store aria-hidden="true" className="size-[18px]" />
                                </span>
                                <span className="flex flex-col leading-tight">
                                    <span className="text-muted text-[11px]">Collect from</span>
                                    <span className="text-sm font-bold">{store.shop.name}</span>
                                </span>
                            </Link>
                        )}
                        <Link
                            href={store.urls.basket}
                            className={cn(
                                'flex min-h-12 items-center gap-2.5 rounded-full py-1.5 pr-1.5 pl-4 font-extrabold transition sm:pl-5',
                                butcher ? 'bg-ink hover:bg-brand text-white' : 'bg-lime text-ink hover:brightness-95',
                            )}
                            aria-label={`Basket: ${basket?.count ?? 0} items, ${money(basket?.total ?? '0')}`}
                        >
                            <ShoppingBag aria-hidden="true" className="size-5 sm:hidden" />
                            <span className="hidden sm:inline">{money(basket?.total ?? '0')}</span>
                            <span
                                className={cn(
                                    'flex size-9 items-center justify-center rounded-full text-sm',
                                    butcher ? 'bg-lime text-ink' : 'bg-ink text-lime',
                                )}
                            >
                                {basket?.count ?? 0}
                            </span>
                        </Link>
                    </div>
                </div>

                {store.departments.length > 0 && (
                    <nav aria-label="Departments" className="mx-auto max-w-[1320px]">
                        <ul className="flex gap-1 overflow-x-auto px-3 pb-3 [scrollbar-width:none] sm:flex-wrap sm:px-4 [&::-webkit-scrollbar]:hidden">
                            {store.departments.map((department) => {
                                const active = current.pathname === department.url;

                                return (
                                    <li key={department.id} className="shrink-0">
                                        <Link
                                            href={department.url}
                                            aria-current={active ? 'page' : undefined}
                                            className={cn(
                                                'text-ink hover:bg-paper flex min-h-11 items-center rounded-full px-3.5 text-sm font-semibold whitespace-nowrap transition-colors',
                                                active && 'bg-ink hover:bg-ink text-white',
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
            </header>
        </>
    );
}
