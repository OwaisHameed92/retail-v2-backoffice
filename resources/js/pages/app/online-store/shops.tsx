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

/** OS-3: the shops customers can collect online orders from, with their hours and slot settings. */
export default function OnlineStoreShops({ hasStore, shops, slotOptions }: ShopsProps) {
    const collecting = shops.filter((s) => s.saved && s.enabled).length;

    return (
        <AppLayout>
            <Head title="Collection shops" />
            <PageHeader
                title="Online store"
                description="Choose where customers collect their orders, when, and how many orders each slot takes."
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
                        {collecting === 0
                            ? 'Add at least one collection shop before you go live.'
                            : `Customers can collect from ${collecting} ${collecting === 1 ? 'shop' : 'shops'}${shops.length > 1 && collecting > 1 ? ' and choose one at checkout' : ''}. They pay at the shop; the till rings the order up.`}
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
                shops.map((shop) => <ShopCard key={shop.id} shop={shop} slotOptions={slotOptions} disabled={!hasStore} />)
            )}
        </AppLayout>
    );
}
