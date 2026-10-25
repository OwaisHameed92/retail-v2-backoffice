import { PageTabs } from '@/components/shared/page-tabs';

export type OnlineStoreTab = 'store' | 'shops' | 'delivery' | 'products' | 'emails' | 'legal';

/** Store | Shops & collection | Delivery & payments (OS-9) | Products | Emails (OS-10) | Legal & contact (OS-11), under the online store page headers. */
export function OnlineStoreTabs({ active }: { active: OnlineStoreTab }) {
    return (
        <PageTabs
            label="Online store sections"
            tabs={[
                { label: 'Store', href: route('app.online-store.edit'), active: active === 'store' },
                { label: 'Shops & collection', href: route('app.online-store.shops'), active: active === 'shops' },
                { label: 'Delivery & payments', href: route('app.online-store.delivery'), active: active === 'delivery' },
                { label: 'Products', href: route('app.online-store.products'), active: active === 'products' },
                { label: 'Emails', href: route('app.online-store.emails'), active: active === 'emails' },
                { label: 'Legal & contact', href: route('app.online-store.legal'), active: active === 'legal' },
            ]}
        />
    );
}
