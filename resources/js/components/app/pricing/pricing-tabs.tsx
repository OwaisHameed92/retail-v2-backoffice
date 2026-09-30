import { PageTabs } from '@/components/shared/page-tabs';

/** Link tabs shared by the prices screens (module 4.3). */
export function PricingTabs({ current }: { current: 'prices' | 'changes' }) {
    return (
        <PageTabs
            label="Price sections"
            tabs={[
                { label: 'Shop prices', href: route('app.prices.index'), active: current === 'prices' },
                { label: 'Price changes from tills', href: route('app.prices.changes'), active: current === 'changes' },
            ]}
        />
    );
}
