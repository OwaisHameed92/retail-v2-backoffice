import { PageTabs } from '@/components/shared/page-tabs';

export type OnlineStoreTab = 'store' | 'shops' | 'products' | 'delivery';

/** Store | Collection shops | Products | Delivery & payments (OS-9), under the online store page headers. */
export function OnlineStoreTabs({ active }: { active: OnlineStoreTab }) {
    return (
        <PageTabs
            label="Online store sections"
            tabs={[
                { label: 'Store', href: route('app.online-store.edit'), active: active === 'store' },
                { label: 'Collection shops', href: route('app.online-store.shops'), active: active === 'shops' },
                { label: 'Products', href: route('app.online-store.products'), active: active === 'products' },
                { label: 'Delivery & payments', href: route('app.online-store.delivery'), active: active === 'delivery' },
            ]}
        />
    );
}
