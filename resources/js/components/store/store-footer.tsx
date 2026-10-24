import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { MapPin, Phone } from 'lucide-react';
import { StoreLogo } from './store-header';
import { useTemplate } from './theme';
import { type StoreInfo } from './types';

const LINK = 'flex min-h-11 items-center text-white/85 underline-offset-4 hover:text-white hover:underline md:min-h-8';

/**
 * The store's footer (OS-8) on a deep shade of the store's colour (white text at 7:1 or more): a call to shop, who it
 * is and where to collect (address, phone, open now), shop links and departments, collection hours, what is sold in
 * the shop only, and "Online store by Switch & Save".
 */
export function StoreFooter({ store }: { store: StoreInfo }) {
    const butcher = useTemplate() === 'butcher';
    const shop = store.shop;

    return (
        <footer className="bg-brand-deep text-white">
            <div className="mx-auto flex max-w-[1280px] flex-col gap-8 px-4 pt-10 pb-28 sm:px-6 md:pb-8">
                <div className="flex flex-col gap-5 border-b border-white/15 pb-8 md:flex-row md:items-center md:justify-between">
                    <p className="font-display text-2xl leading-tight font-bold tracking-[-0.02em] sm:text-[28px]">
                        {butcher ? 'Cut to order.' : 'Order online.'} <span className="text-lime">Collect and pay in the shop.</span>
                    </p>
                    <Link
                        href={store.urls.search}
                        className="bg-lime text-on-lime inline-flex h-11 shrink-0 items-center self-start rounded-full px-5 text-sm font-semibold transition hover:brightness-95 md:self-auto"
                    >
                        {butcher ? 'Browse all cuts' : 'Browse all products'}
                    </Link>
                </div>
                <div className="grid gap-8 text-[13px] sm:grid-cols-2 lg:grid-cols-4">
                    <div className="flex min-w-0 items-start gap-3">
                        {store.logoUrl ? (
                            <span className="shrink-0 rounded-lg bg-white p-1.5">
                                <StoreLogo store={store} size="sm" />
                            </span>
                        ) : (
                            <StoreLogo store={store} size="sm" withName={false} />
                        )}
                        <div className="flex min-w-0 flex-col gap-1">
                            <span className="text-sm font-semibold">{store.name}</span>
                            {shop && <span className="text-white/75">Collect from {shop.name}</span>}
                            {shop?.openNow && (
                                <span className="flex items-center gap-1.5 font-semibold text-white">
                                    <span aria-hidden="true" className={cn('size-2 rounded-full', shop.openNow.open ? 'bg-lime' : 'bg-white/50')} />
                                    {shop.openNow.label}
                                </span>
                            )}
                            {shop?.address && (
                                <span className="flex items-start gap-1.5 text-white/75">
                                    <MapPin aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                                    {shop.address}
                                </span>
                            )}
                            {shop?.phone && (
                                <a href={`tel:${shop.phone.replace(/\s+/g, '')}`} className={`${LINK} gap-1.5 self-start`}>
                                    <Phone aria-hidden="true" className="size-4 shrink-0" />
                                    {shop.phone}
                                </a>
                            )}
                        </div>
                    </div>
                    <nav aria-label="Footer" className="flex flex-col">
                        <span className="mb-1 text-sm font-semibold">Shop</span>
                        <Link href={store.urls.home} className={LINK}>
                            Home
                        </Link>
                        <Link href={store.urls.search} className={LINK}>
                            {butcher ? 'All cuts' : 'All products'}
                        </Link>
                        {store.departments.slice(0, 5).map((department) => (
                            <Link key={department.id} href={department.url} className={LINK}>
                                {department.name}
                            </Link>
                        ))}
                        <Link href={store.urls.basket} className={LINK}>
                            Basket
                        </Link>
                    </nav>
                    <div className="flex flex-col gap-1">
                        <span className="mb-1 text-sm font-semibold">Collection hours</span>
                        {shop?.hours && shop.hours.length > 0 ? (
                            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-white/80">
                                {shop.hours.map((day) => (
                                    <div key={day.day} className="contents">
                                        <dt>{day.day.slice(0, 3)}</dt>
                                        <dd className="tabular-nums">{day.hours ? `${day.hours.open}–${day.hours.close}` : 'Closed'}</dd>
                                    </div>
                                ))}
                            </dl>
                        ) : (
                            <span className="leading-relaxed text-white/75">Pick a collection time at checkout.</span>
                        )}
                    </div>
                    <div className="flex flex-col gap-1">
                        <span className="mb-1 text-sm font-semibold">How it works</span>
                        <span className="leading-relaxed text-white/75">
                            {butcher
                                ? 'Choose the cut and the weight, then a collection time. We weigh it at the counter and you pay the real weight there.'
                                : 'Pick a collection time at checkout. We get your order ready, you pay at the counter when you collect.'}
                        </span>
                        <span className="mt-3 mb-1 text-sm font-semibold">In the shop only</span>
                        <span className="leading-relaxed text-white/75">
                            Alcohol, tobacco, vapes, lottery, knives and pharmacy medicines are sold in the shop only.
                        </span>
                    </div>
                </div>
                <div className="flex flex-wrap justify-between gap-x-6 gap-y-2 border-t border-white/15 pt-5 text-xs text-white/65">
                    <span>
                        &copy; {new Date().getFullYear()} {store.name}
                    </span>
                    <span>Online store by Switch &amp; Save</span>
                </div>
            </div>
        </footer>
    );
}
