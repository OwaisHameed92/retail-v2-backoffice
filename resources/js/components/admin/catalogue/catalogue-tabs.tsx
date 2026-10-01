import { PageTabs } from '@/components/shared/page-tabs';

/** Link tabs of the admin master catalogue: products, the till review queue, CSV loads. */
export function CatalogueTabs({ pending }: { pending?: number }) {
    return (
        <PageTabs
            label="Catalogue sections"
            tabs={[
                { label: 'Products', href: route('admin.catalogue.index'), active: route().current('admin.catalogue.index') },
                {
                    label: 'Review queue',
                    href: route('admin.catalogue.contributions.index'),
                    active: route().current('admin.catalogue.contributions.*'),
                    count: pending,
                },
                { label: 'CSV loads', href: route('admin.catalogue.imports.index'), active: route().current('admin.catalogue.imports.*') },
            ]}
        />
    );
}
