import { AboutSection, DepartmentsSection, FeaturedSection, HeroSection, HowItWorksSection, ShopsSection } from '@/components/store/home-sections';
import { StoreLayout } from '@/components/store/store-layout';
import { type ShopInfo, type StoreProduct } from '@/components/store/types';
import { useStore } from '@/components/store/use-store';
import { type ReactNode } from 'react';

type Section = 'hero' | 'departments' | 'featured' | 'howItWorks' | 'shops' | 'about';

interface Props {
    sections: Section[];
    headline: string | null;
    bannerUrl: string;
    nextCollection: string | null;
    featured: StoreProduct[];
    shops: ShopInfo[];
}

/** The store's home page (Fresh Market): the sections the store turned on, in its order. */
export default function StoreHome({ sections, headline, bannerUrl, nextCollection, featured, shops }: Props) {
    const store = useStore();

    if (!store) {
        return null;
    }

    const prepMinutes = shops.find((shop) => shop.id === store.shop?.id)?.prepMinutes ?? shops[0]?.prepMinutes ?? null;
    const render: Record<Section, () => ReactNode> = {
        hero: () => <HeroSection store={store} headline={headline} bannerUrl={bannerUrl} nextCollection={nextCollection} prepMinutes={prepMinutes} />,
        departments: () => <DepartmentsSection store={store} />,
        featured: () => <FeaturedSection store={store} products={featured} />,
        howItWorks: () => <HowItWorksSection prepMinutes={prepMinutes} />,
        shops: () => <ShopsSection shops={shops} />,
        about: () => <AboutSection store={store} prepMinutes={prepMinutes} />,
    };

    return (
        <StoreLayout title={store.name} active="home">
            {!sections.includes('hero') && <h1 className="sr-only">{store.name}</h1>}
            <div className="flex flex-col gap-10 sm:gap-14">
                {sections.map((section) => (
                    <div key={section} className="empty:hidden">
                        {render[section]?.()}
                    </div>
                ))}
            </div>
        </StoreLayout>
    );
}
