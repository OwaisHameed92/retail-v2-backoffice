import { cn } from '@/lib/utils';
import { Head, Link, usePage } from '@inertiajs/react';
import { Home, Search, ShoppingBag } from 'lucide-react';
import { type CSSProperties, type ReactNode } from 'react';
import { onColour } from './format';
import { StoreFooter } from './store-footer';
import { StoreHeader } from './store-header';
import { StoreNotices } from './store-notices';
import { templateOf } from './theme';
import { type StorePageProps } from './types';

/**
 * The public store's frame (OS-2, OS-5): the shop's own brand (logo, colour) and template (`data-template` swaps the
 * fonts and colours in store.css), never the portal's. Skip link, header with search and basket, departments,
 * notices, the page, footer, and a bottom bar on phones. The brand colour is set as `--color-brand` here (the store's
 * colour, else the template's default from the server).
 */
export function StoreLayout({ title, children, active }: { title: string; children: ReactNode; active?: 'home' | 'search' | 'basket' }) {
    const { store, basket } = usePage<StorePageProps>().props;
    const template = templateOf(store?.template);
    const brand = store?.brand ?? '#0B4A34';
    const style = { '--color-brand': brand, '--color-on-brand': onColour(brand) } as CSSProperties;
    const pageTitle = store ? (title === store.name ? title : `${title} · ${store.name}`) : title;

    return (
        <div style={style} data-template={template} className="bg-paper text-ink flex min-h-screen flex-col font-sans">
            <Head title={pageTitle} />
            <a
                href="#main"
                className="bg-ink sr-only z-50 rounded-full px-5 py-3 font-bold text-white focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
            >
                Skip to content
            </a>

            {store && <StoreHeader store={store} basket={basket} />}

            <main
                id="main"
                tabIndex={-1}
                className={cn('mx-auto w-full max-w-[1320px] flex-1 px-4 pt-5 pb-28 outline-none sm:px-6 sm:pt-6 md:pb-16')}
            >
                {store && <StoreNotices store={store} basket={basket} />}
                {children}
            </main>

            {store && <StoreFooter store={store} />}

            {store && (
                <nav
                    aria-label="Shop"
                    className="border-line fixed inset-x-0 bottom-0 z-40 grid grid-cols-3 border-t bg-white/95 px-[max(0.5rem,env(safe-area-inset-left))] pt-1.5 pb-[max(0.5rem,env(safe-area-inset-bottom))] backdrop-blur md:hidden"
                >
                    <BottomLink
                        href={store.urls.home}
                        label="Home"
                        current={active === 'home'}
                        icon={<Home className="size-5" aria-hidden="true" />}
                    />
                    <BottomLink
                        href={store.urls.search}
                        label="Search"
                        current={active === 'search'}
                        icon={<Search className="size-5" aria-hidden="true" />}
                    />
                    <BottomLink
                        href={store.urls.basket}
                        label={basket && basket.count > 0 ? `Basket (${basket.count})` : 'Basket'}
                        current={active === 'basket'}
                        icon={
                            <span className="relative">
                                <ShoppingBag className="size-5" aria-hidden="true" />
                                {basket && basket.count > 0 && (
                                    <span
                                        aria-hidden="true"
                                        className="bg-ink text-lime absolute -top-2 -right-3 min-w-5 rounded-full px-1 text-center text-[11px] leading-5 font-bold"
                                    >
                                        {basket.count > 99 ? '99+' : basket.count}
                                    </span>
                                )}
                            </span>
                        }
                    />
                </nav>
            )}
        </div>
    );
}

function BottomLink({ href, label, icon, current }: { href: string; label: string; icon: ReactNode; current: boolean }) {
    return (
        <Link
            href={href}
            aria-current={current ? 'page' : undefined}
            className="text-ink flex min-h-12 flex-col items-center justify-center gap-1 rounded-2xl text-xs font-bold"
        >
            <span className={cn('flex h-7 w-12 items-center justify-center rounded-full', current && 'bg-lime')}>{icon}</span>
            {label}
        </Link>
    );
}
