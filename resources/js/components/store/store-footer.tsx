import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Mail, MapPin, Phone } from 'lucide-react';
import { type ReactNode } from 'react';
import { StoreLogo } from './store-header';
import { useTemplate } from './theme';
import { type StoreInfo } from './types';

const LINK = 'inline-flex min-h-10 items-center text-white/80 underline-offset-4 transition hover:text-white hover:underline md:min-h-8';

function Column({ title, children, wide = false }: { title: string; children: ReactNode; wide?: boolean }) {
    return (
        <div className={cn('flex min-w-0 flex-col gap-1', wide && 'col-span-2 sm:col-span-1')}>
            <h2 className="mb-2 text-xs font-semibold tracking-[0.08em] text-white uppercase">{title}</h2>
            {children}
        </div>
    );
}

/**
 * The store's footer (OS-8, redesigned OS-11 for the owner): on a deep shade of the store's colour (white text at 7:1
 * or more). A top row with the logo (or the name), a short line, the phone, email and collection shop address; then
 * evenly spaced columns (Shop, Help, Legal, Opening hours); then a bottom bar with the trader's details (audit OSA-2)
 * and "Online store by Switch & Save". Nothing is squeezed: columns stack two by two on tablets and one by one at 360 px.
 */
export function StoreFooter({ store }: { store: StoreInfo }) {
    const butcher = useTemplate() === 'butcher';
    const shop = store.shop;
    const trader = store.trader;
    const phone = shop?.phone ?? trader?.phone ?? null;
    const email = trader?.email ?? null;
    const legal = [
        { href: store.urls.terms, label: 'Terms of sale' },
        { href: store.urls.privacy, label: 'Privacy notice' },
        { href: store.urls.cookies, label: 'Cookies' },
    ].filter((l): l is { href: string; label: string } => !!l.href);
    const company = [
        trader ? (trader.legalName === trader.tradingName ? trader.legalName : `${trader.legalName}, trading as ${trader.tradingName}`) : store.name,
        trader?.companyNumber ? `Company no. ${trader.companyNumber}` : null,
        trader?.vatNumber ? `VAT no. ${trader.vatNumber}` : null,
        trader?.address ? `Registered address: ${trader.address}` : null,
    ].filter(Boolean);

    return (
        <footer className="bg-brand-deep text-white">
            <div className="mx-auto flex max-w-[1280px] flex-col px-4 pt-12 pb-28 sm:px-6 md:pb-10">
                <div className="grid gap-8 border-b border-white/15 pb-10 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] md:gap-12">
                    <div className="flex flex-col items-start gap-4">
                        {store.logoUrl ? (
                            <span className="rounded-xl bg-white px-3 py-2">
                                <StoreLogo store={store} size="sm" withName={false} />
                                <span className="sr-only">{store.name}</span>
                            </span>
                        ) : (
                            <span className="font-display text-2xl leading-tight font-bold tracking-[-0.02em]">{store.name}</span>
                        )}
                        <p className="max-w-md text-[15px] leading-relaxed text-white/80">
                            {store.wording?.footer ??
                                (butcher
                                    ? 'Cut to order by your local butcher. Order online, we weigh it at the counter and you pay the real weight.'
                                    : 'Your local shop, online. Order from the shelves you know, at the shelf price, ready when you are.')}
                        </p>
                    </div>
                    <div className="grid content-start gap-3 text-[15px] sm:grid-cols-2 sm:gap-x-8">
                        {phone && (
                            <a
                                href={`tel:${phone.replace(/[^\d+]/g, '')}`}
                                className="flex min-h-10 items-center gap-2.5 font-semibold hover:underline"
                            >
                                <Phone aria-hidden="true" className="size-[18px] shrink-0 text-white/70" />
                                {phone}
                            </a>
                        )}
                        {email && (
                            <a
                                href={`mailto:${email}`}
                                className="flex min-h-10 min-w-0 items-center gap-2.5 font-semibold [overflow-wrap:anywhere] hover:underline sm:col-span-2 lg:col-span-1"
                            >
                                <Mail aria-hidden="true" className="size-[18px] shrink-0 text-white/70" />
                                <span className="min-w-0">{email}</span>
                            </a>
                        )}
                        {shop?.address && (
                            <p className="flex items-start gap-2.5 leading-snug text-white/85 sm:col-span-2">
                                <MapPin aria-hidden="true" className="mt-0.5 size-[18px] shrink-0 text-white/70" />
                                <span>
                                    <span className="font-semibold text-white">{shop.name}</span>
                                    <span className="block">{shop.address}</span>
                                </span>
                            </p>
                        )}
                        {shop?.openNow && (
                            <p className="flex items-center gap-2.5 text-sm text-white/85 sm:col-span-2">
                                <span
                                    aria-hidden="true"
                                    className={cn('ml-1.5 size-2 shrink-0 rounded-full', shop.openNow.open ? 'bg-lime' : 'bg-white/50')}
                                />
                                <span className="ml-1">{shop.openNow.label}</span>
                            </p>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-x-6 gap-y-10 py-10 text-sm sm:gap-x-8 lg:grid-cols-4">
                    <Column title="Shop" wide>
                        <Link href={store.urls.search} className={LINK}>
                            {butcher ? 'All cuts' : 'All products'}
                        </Link>
                        {store.departments.slice(0, 6).map((department) => (
                            <Link key={department.id} href={department.url} className={LINK}>
                                {department.name}
                            </Link>
                        ))}
                        <Link href={store.urls.basket} className={LINK}>
                            Basket
                        </Link>
                    </Column>
                    <Column title="Help">
                        <Link href={`${store.urls.home}#how-it-works`} className={LINK}>
                            How it works
                        </Link>
                        <Link href={`${store.urls.home}#shops`} className={LINK}>
                            {store.wording?.helpLink ?? 'Delivery & collection'}
                        </Link>
                        {store.urls.contact && (
                            <Link href={store.urls.contact} className={LINK}>
                                Contact us
                            </Link>
                        )}
                        <p className="mt-2 max-w-[16rem] text-xs leading-relaxed text-white/65">
                            Alcohol, tobacco, vapes, lottery, knives and pharmacy medicines are sold in the shop only.
                        </p>
                    </Column>
                    <Column title="Legal">
                        {legal.map((item) => (
                            <Link key={item.label} href={item.href} className={LINK}>
                                {item.label}
                            </Link>
                        ))}
                    </Column>
                    <Column title={store.wording?.hoursTitle ?? 'Opening hours'} wide>
                        {shop?.hours && shop.hours.length > 0 ? (
                            <dl className="grid max-w-[16rem] grid-cols-[minmax(0,1fr)_auto] gap-x-6 gap-y-1.5 text-white/80">
                                {shop.hours.map((day) => (
                                    <div key={day.day} className="contents">
                                        <dt>{day.day}</dt>
                                        <dd className="text-right tabular-nums">{day.hours ? `${day.hours.open}–${day.hours.close}` : 'Closed'}</dd>
                                    </div>
                                ))}
                            </dl>
                        ) : (
                            <span className="leading-relaxed text-white/75">Choose a time at checkout.</span>
                        )}
                    </Column>
                </div>

                <div className="flex flex-col gap-3 border-t border-white/15 pt-6 text-xs leading-relaxed text-white/65 md:flex-row md:items-end md:justify-between md:gap-10">
                    <p className="max-w-3xl">
                        &copy; {new Date().getFullYear()} {company.join(' · ')}
                    </p>
                    <p className="shrink-0">Online store by Switch &amp; Save</p>
                </div>
            </div>
        </footer>
    );
}
