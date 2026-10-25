import { ClosedDays } from '@/components/app/online-store/closed-days';
import { OnlineStoreTabs } from '@/components/app/online-store/online-store-tabs';
import { ShopCard } from '@/components/app/online-store/shop-card';
import { type ShopsProps } from '@/components/app/online-store/types';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Info, Store, TriangleAlert } from 'lucide-react';

/**
 * OS-3: the shops customers can collect online orders from, with their hours and slot settings. OS-12: a shop can be
 * delivery-only (collection off here, delivery on on the Delivery & payments tab); delivery never depends on this tab.
 */
export default function OnlineStoreShops({ hasStore, shops, slotOptions }: ShopsProps) {
    const collecting = shops.filter((s) => s.saved && s.enabled).length;
    const deliveryOnly = shops.filter((s) => s.saved && !s.enabled && s.delivers).length;
    const deliveryLink = (
        <Link href={route('app.online-store.delivery')} className="text-primary font-medium hover:underline">
            Delivery &amp; payments
        </Link>
    );

    return (
        <AppLayout>
            <Head title="Shops & collection" />
            <PageHeader
                title="Online store"
                description="Choose the shops that take online orders, where and when customers collect, and how many orders each slot takes. A shop can also deliver only."
                tabs={<OnlineStoreTabs active="shops" />}
            />

            {!hasStore ? (
                <Alert variant="warning">
                    <TriangleAlert />
                    <AlertDescription>
                        <span>
                            Your online store is not switched on yet. Contact Switch &amp; Save to switch it on, then add the shops customers collect
                            from.
                        </span>
                    </AlertDescription>
                </Alert>
            ) : (
                <Alert variant="info">
                    <Info />
                    <AlertDescription>
                        <span>
                            {collecting === 0
                                ? deliveryOnly > 0
                                    ? `No shop offers collection: ${deliveryOnly === 1 ? 'your shop delivers' : `${deliveryOnly} shops deliver`} only. `
                                    : 'Add a shop for collection, or add it with collection off and turn on delivery, before you go live. '
                                : `Customers can collect from ${collecting} ${collecting === 1 ? 'shop' : 'shops'}${shops.length > 1 && collecting > 1 ? ' and choose one at checkout' : ''}. The till rings each order up. `}
                            Delivery is set up on {deliveryLink}, with or without collection.
                        </span>
                    </AlertDescription>
                </Alert>
            )}

            {shops.length === 0 ? (
                <EmptyState
                    icon={Store}
                    title="No open shops"
                    body="Shops appear here once they are set up on the Shops and tills page."
                    action={
                        <Button variant="outline" asChild>
                            <Link href={route('app.shops.index')}>Shops and tills</Link>
                        </Button>
                    }
                />
            ) : (
                shops.map((shop) => (
                    <div key={shop.id} className="grid gap-3">
                        <ShopCard shop={shop} slotOptions={slotOptions} disabled={!hasStore} />
                        {shop.saved && <ClosedDays branchId={shop.id} closures={shop.closures ?? []} disabled={!hasStore} />}
                    </div>
                ))
            )}
        </AppLayout>
    );
}
