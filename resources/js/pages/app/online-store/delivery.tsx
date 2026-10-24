import { DeliveryShopCard } from '@/components/app/online-store/delivery-shop-card';
import { OnlineStoreTabs } from '@/components/app/online-store/online-store-tabs';
import { DnaCard, WaysToPayCard } from '@/components/app/online-store/payment-settings-card';
import { type DeliveryPaymentsProps } from '@/components/app/online-store/types';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Info, TriangleAlert, Truck } from 'lucide-react';

/** OS-9: local delivery per collection shop, the ways to pay, and card payments online with DNA Payments. */
export default function OnlineStoreDelivery({ hasStore, canEditPayments, shops, slotOptions, payments, dna }: DeliveryPaymentsProps) {
    const delivering = shops.filter((s) => s.enabled).length;

    return (
        <AppLayout>
            <Head title="Delivery & payments" />
            <PageHeader
                title="Online store"
                description="Deliver to customers near your shops, and choose how they pay: at the shop, on delivery or by card online."
                tabs={<OnlineStoreTabs active="delivery" />}
            />

            {!hasStore ? (
                <Alert variant="warning">
                    <TriangleAlert />
                    <AlertDescription>
                        <span>Your online store is not switched on yet. Contact Switch &amp; Save to switch it on.</span>
                    </AlertDescription>
                </Alert>
            ) : (
                <Alert variant="info">
                    <Info />
                    <AlertDescription>
                        {delivering === 0
                            ? 'No shop delivers yet. Turn delivery on for a shop and add the postcode areas it covers.'
                            : `${delivering} ${delivering === 1 ? 'shop delivers' : 'shops deliver'}. Delivery orders show on the Online orders board with their address; ring them up on the till before they go out.`}
                    </AlertDescription>
                </Alert>
            )}

            {payments && canEditPayments && <WaysToPayCard payments={payments} disabled={!hasStore} />}
            {dna && canEditPayments && <DnaCard dna={dna} disabled={!hasStore} />}

            {shops.length === 0 ? (
                <EmptyState
                    icon={Truck}
                    title="No collection shops yet"
                    body="Add a collection shop first: delivery is set up per collection shop."
                    action={
                        <Button variant="outline" asChild>
                            <Link href={route('app.online-store.shops')}>Collection shops</Link>
                        </Button>
                    }
                />
            ) : (
                shops.map((shop) => <DeliveryShopCard key={shop.id} shop={shop} slotOptions={slotOptions} disabled={!hasStore} />)
            )}
        </AppLayout>
    );
}
