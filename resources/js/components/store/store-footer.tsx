import { Link } from '@inertiajs/react';
import { MapPin, Phone } from 'lucide-react';
import { StoreLogo } from './store-header';
import { useTemplate } from './theme';
import { type StoreInfo } from './types';

const LINK = 'flex min-h-11 items-center text-white/85 hover:text-white hover:underline md:min-h-8';

/**
 * The store's footer: who it is and where to collect (address, phone), shop links, what is sold in the shop only,
 * and "Online store by Switch & Save". The butcher's halal certificate line needs a store setting that does not
 * exist yet, so it is not shown.
 */
export function StoreFooter({ store }: { store: StoreInfo }) {
    const butcher = useTemplate() === 'butcher';

    return (
        <footer className="bg-ink text-white">
            <div className="mx-auto flex max-w-[1320px] flex-col gap-10 px-4 pt-12 pb-28 sm:px-6 md:pb-10">
                <div className="flex flex-col gap-8 border-b border-white/15 pb-10 md:flex-row md:items-end md:justify-between">
                    <p className="font-display max-w-2xl text-4xl leading-[0.95] font-extrabold tracking-[-0.04em] sm:text-5xl">
                        {butcher ? 'Cut to order.' : 'Order online.'}
                        <br />
                        Collect and pay in the shop.
                    </p>
                    <Link
                        href={store.urls.search}
                        className="bg-lime text-ink inline-flex min-h-14 items-center self-start rounded-full px-7 font-extrabold hover:brightness-95 md:self-auto"
                    >
                        {butcher ? 'Browse all cuts' : 'Browse all products'}
                    </Link>
                </div>
                <div className="grid gap-8 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div className="flex min-w-0 items-start gap-3">
                        <StoreLogo store={store} size="sm" />
                        <div className="flex min-w-0 flex-col gap-1">
                            <span className="font-bold">{store.name}</span>
                            {store.shop && <span className="text-white/75">Collect from {store.shop.name}</span>}
                            {store.shop?.address && (
                                <span className="flex items-start gap-1.5 text-white/75">
                                    <MapPin aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                                    {store.shop.address}
                                </span>
                            )}
                            {store.shop?.phone && (
                                <a href={`tel:${store.shop.phone.replace(/\s+/g, '')}`} className={`${LINK} gap-1.5 self-start`}>
                                    <Phone aria-hidden="true" className="size-4 shrink-0" />
                                    {store.shop.phone}
                                </a>
                            )}
                        </div>
                    </div>
                    <nav aria-label="Footer" className="flex flex-col">
                        <span className="mb-1 font-bold">Shop</span>
                        <Link href={store.urls.home} className={LINK}>
                            Home
                        </Link>
                        <Link href={store.urls.search} className={LINK}>
                            {butcher ? 'All cuts' : 'All products'}
                        </Link>
                        <Link href={store.urls.basket} className={LINK}>
                            Basket
                        </Link>
                    </nav>
                    <div className="flex flex-col gap-1">
                        <span className="mb-1 font-bold">How it works</span>
                        <span className="leading-relaxed text-white/75">
                            {butcher
                                ? 'Choose the cut and the weight, then a collection time. We weigh it at the counter and you pay the real weight there.'
                                : 'Pick a collection time at checkout. We get your order ready, you pay at the counter when you collect.'}
                        </span>
                    </div>
                    <div className="flex flex-col gap-1">
                        <span className="mb-1 font-bold">In the shop only</span>
                        <span className="leading-relaxed text-white/75">
                            Alcohol, tobacco, vapes, lottery, knives and pharmacy medicines are sold in the shop only.
                        </span>
                    </div>
                </div>
                <div className="flex flex-wrap justify-between gap-x-6 gap-y-2 text-[13px] text-white/70">
                    <span>
                        &copy; {new Date().getFullYear()} {store.name}
                    </span>
                    <span>Online store by Switch &amp; Save</span>
                </div>
            </div>
        </footer>
    );
}
