import { cn } from '@/lib/utils';
import { Head, Link, usePage } from '@inertiajs/react';
import { Home, Search, ShoppingBag } from 'lucide-react';
import { type CSSProperties, type ReactNode } from 'react';
import { onColour } from './format';
import { StoreFooter } from './store-footer';
import { StoreHeader } from './store-header';
import { StoreNotices } from './store-notices';
import { templateOf } from './theme';
import { type StorePageProps, type StorePalette } from './types';

/** The CSS custom properties of the store's colours (the same ones store.blade.php sets on <html>). */
export function paletteStyle(palette: StorePalette | undefined, brand: string): CSSProperties {
    if (!palette) {
        return { '--color-brand': brand, '--color-on-brand': onColour(brand) } as CSSProperties;
    }

    return {
        '--color-brand': palette.brand,
        '--color-on-brand': palette.onBrand,
        '--color-brand-hover': palette.brandHover,
        '--color-brand-text': palette.brandText,
        '--color-brand-deep': palette.brandDeep,
        '--color-brand-soft': palette.brandSoft,
        '--color-brand-tint': palette.brandTint,
        '--color-lime': palette.highlight,
        '--color-on-lime': palette.onHighlight,
        '--color-lime-soft': palette.highlightSoft,
    } as CSSProperties;
}

/**
 * The public store's frame (OS-2, OS-5, OS-8): the shop's own brand (logo, colours from its one brand colour,
 * BrandPalette) and template (`data-template` swaps the fonts in store.css), never the portal's. Skip link, header
 * with search, shop and basket, departments, notices, the page, footer, and a bottom bar on phones.
 */
export function StoreLayout({ title, children, active }: { title: string; children: ReactNode; active?: 'home' | 'search' | 'basket' }) {
    const { store, basket } = usePage<StorePageProps>().props;
    const template = templateOf(store?.template);
    const style = paletteStyle(store?.palette, store?.brand ?? '#0B4A34');
    const pageTitle = store ? (title === store.name ? title : `${title} · ${store.name}`) : title;
    const count = basket?.count ?? 0;

    return (
        <div style={style} data-template={template} className="bg-paper text-ink flex min-h-screen flex-col font-sans text-[15px] leading-normal">
            <Head title={pageTitle} />
            <a
                href="#main"
                className="bg-brand text-on-brand sr-only z-50 rounded-full px-5 py-3 font-bold focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
            >
                Skip to content
            </a>

            {store && <StoreHeader store={store} basket={basket} />}

            <main id="main" tabIndex={-1} className="mx-auto w-full max-w-[1280px] flex-1 px-4 pt-4 pb-28 outline-none sm:px-6 sm:pt-6 md:pb-16">
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
                        label={count > 0 ? `Basket (${count})` : 'Basket'}
                        current={active === 'basket'}
                        icon={
                            <span className="relative">
                                <ShoppingBag className="size-5" aria-hidden="true" />
                                {count > 0 && (
                                    <span
                                        aria-hidden="true"
                                        className="bg-lime text-on-lime ring-brand absolute -top-2 -right-3 min-w-5 rounded-full px-1 text-center text-[11px] leading-5 font-bold ring-2"
                                    >
                                        {count > 99 ? '99+' : count}
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
            className={cn(
                'flex min-h-12 flex-col items-center justify-center gap-0.5 rounded-2xl text-[11px] font-semibold',
                current ? 'text-brand-text' : 'text-body',
            )}
        >
            <span className={cn('flex h-7 w-12 items-center justify-center rounded-full transition-colors', current && 'bg-brand text-on-brand')}>
                {icon}
            </span>
            {label}
        </Link>
    );
}
