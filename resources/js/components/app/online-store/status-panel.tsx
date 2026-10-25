import { StatusPill } from '@/components/shared/status-badge';
import { showToast } from '@/components/shared/toaster';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { formatNumber } from '@/lib/country';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { CircleCheck, CircleDashed, Copy, Globe } from 'lucide-react';
import { useState } from 'react';
import { ToggleSwitch } from './toggle-switch';
import { type BuilderProps, type ShopMode, type StoreSettings } from './types';

/** OS-12: the words for what the store's shops offer together (collection, delivery or both). */
const MODE_WORDS: Record<ShopMode, { orders: string; shops: string }> = {
    collection: { orders: 'click & collect', shops: 'collection' },
    delivery: { orders: 'delivery', shops: 'delivery' },
    both: { orders: 'click & collect and delivery', shops: 'collection or delivery' },
};

/** Sends one of the store switches; a refusal (e.g. no shop taking orders yet) shows as a toast. */
export function putSwitch(name: 'app.online-store.live' | 'app.online-store.taking-orders', on: boolean, done: () => void) {
    router.put(
        route(name),
        { on },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => showToast(Object.values(errors)[0] ?? 'That did not work. Try again.', 'error'),
            onFinish: done,
        },
    );
}

function Step({ done, children }: { done: boolean; children: React.ReactNode }) {
    return (
        <li className="flex items-start gap-2 text-sm">
            {done ? (
                <CircleCheck className="text-success mt-0.5 size-4 shrink-0" aria-label="Done" />
            ) : (
                <CircleDashed className="text-muted-foreground mt-0.5 size-4 shrink-0" aria-label="To do" />
            )}
            <span className={cn(!done && 'text-muted-foreground')}>{children}</span>
        </li>
    );
}

/** The store's state at the top of the builder: address with copy and open, "Taking orders" switch, and what is left before going live. */
export function StatusPanel({ store, checklist, canEdit }: { store: StoreSettings | null; checklist: BuilderProps['checklist']; canEdit: boolean }) {
    const [busy, setBusy] = useState(false);
    const copy = async () => {
        if (!store) {
            return;
        }
        try {
            await navigator.clipboard.writeText(store.address);
            showToast('Store address copied.');
        } catch {
            showToast('Copy did not work. Select the address and copy it.', 'error');
        }
    };

    return (
        <Card className="grid gap-5 p-5 sm:p-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,20rem)] lg:gap-10">
            <div className="grid content-start gap-4">
                <div className="flex items-start gap-3">
                    <span
                        className={cn(
                            'flex size-10 shrink-0 items-center justify-center rounded-xl',
                            store?.isLive ? 'bg-success-soft text-success-foreground' : 'bg-muted text-muted-foreground',
                        )}
                    >
                        <Globe className="size-5" />
                    </span>
                    <div className="min-w-0">
                        <p className="text-base font-semibold">
                            {!store ? 'Your store is not set up yet' : store.isLive ? 'Your store is live' : 'Your store is offline'}
                        </p>
                        {store ? (
                            <p className="text-muted-foreground font-mono text-sm break-all">{store.address}</p>
                        ) : (
                            <p className="text-muted-foreground text-sm">
                                Choose a look and an address below, then save. Nobody sees it until you go live.
                            </p>
                        )}
                    </div>
                </div>
                {store && (
                    <div className="flex flex-wrap gap-2">
                        <Button type="button" variant="outline" size="sm" onClick={copy}>
                            <Copy />
                            Copy address
                        </Button>
                    </div>
                )}
                {store && !store.isLive && <p className="text-muted-foreground -mt-2 text-xs">The address opens for customers once you go live.</p>}
                {store && (
                    <div className="flex items-start justify-between gap-4 rounded-lg border p-3">
                        <div>
                            <label htmlFor="taking-orders" className="text-sm font-medium">
                                Taking orders
                            </label>
                            <p className="text-muted-foreground text-xs leading-5">
                                {store.takingOrders
                                    ? `Customers can place ${MODE_WORDS[checklist.shopMode ?? 'collection'].orders} orders.`
                                    : 'Paused: customers can look around but cannot order.'}
                            </p>
                        </div>
                        <ToggleSwitch
                            id="taking-orders"
                            checked={store.takingOrders}
                            disabled={!canEdit}
                            busy={busy}
                            onCheckedChange={(on) => {
                                setBusy(true);
                                putSwitch('app.online-store.taking-orders', on, () => setBusy(false));
                            }}
                        />
                    </div>
                )}
            </div>
            <div className="grid content-start gap-3">
                <div className="flex items-center justify-between gap-2">
                    <p className="text-sm font-semibold">Ready to go live</p>
                    {store?.isLive && <StatusPill tone="success">Live</StatusPill>}
                </div>
                <ul className="grid gap-2">
                    <Step done={store !== null}>Look, address and brand saved</Step>
                    <Step done={checklist.collectionShops > 0}>
                        {checklist.collectionShops > 0 ? (
                            `${checklist.collectionShops} ${checklist.collectionShops === 1 ? 'shop' : 'shops'} for ${MODE_WORDS[checklist.shopMode ?? 'collection'].shops}`
                        ) : (
                            <>
                                <Link href={route('app.online-store.shops')} className="text-primary font-medium hover:underline">
                                    Add a shop
                                </Link>{' '}
                                for collection, or turn on{' '}
                                <Link href={route('app.online-store.delivery')} className="text-primary font-medium hover:underline">
                                    delivery
                                </Link>
                            </>
                        )}
                    </Step>
                    <Step done={(checklist.productsShown ?? 0) > 0}>
                        {checklist.productsShown === null ? (
                            'Products show once a shop is set up'
                        ) : (
                            <>
                                <Link href={route('app.online-store.products')} className="text-primary font-medium hover:underline">
                                    {formatNumber(checklist.productsShown)} products
                                </Link>{' '}
                                online at {checklist.productsShop}
                            </>
                        )}
                    </Step>
                    <Step done={checklist.legalMissing.length === 0}>
                        {checklist.legalMissing.length === 0 ? (
                            'Trader details for your terms, privacy and contact pages'
                        ) : (
                            <>
                                <Link href={route('app.online-store.legal')} className="text-primary font-medium hover:underline">
                                    Add your legal details
                                </Link>{' '}
                                ({checklist.legalMissing.join(', ')})
                            </>
                        )}
                    </Step>
                </ul>
            </div>
        </Card>
    );
}
