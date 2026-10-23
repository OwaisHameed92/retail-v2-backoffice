import { ButcherHero, ButcherPicks, ButcherSteps, MeatTiles } from '@/components/store/butcher-sections';
import { AboutSection, DepartmentsSection, FeaturedSection, HeroSection, HowItWorksSection, ShopsSection } from '@/components/store/home-sections';
import { StoreLayout } from '@/components/store/store-layout';
import { templateOf } from '@/components/store/theme';
import { type ShopInfo, type StoreInfo, type StoreProduct, type StoreTemplate } from '@/components/store/types';
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

type Draw = (props: Props, store: StoreInfo, prepMinutes: number | null) => ReactNode;

/** How each template draws each home section. Shops and about are shared; their words follow the template. */
const SECTIONS: Record<StoreTemplate, Record<Section, Draw>> = {
    freshMarket: {
        hero: (p, store, prep) => (
            <HeroSection store={store} headline={p.headline} bannerUrl={p.bannerUrl} nextCollection={p.nextCollection} prepMinutes={prep} />
        ),
        departments: (_p, store) => <DepartmentsSection store={store} />,
        featured: (p, store) => <FeaturedSection store={store} products={p.featured} />,
        howItWorks: (_p, _store, prep) => <HowItWorksSection prepMinutes={prep} />,
        shops: (p) => <ShopsSection shops={p.shops} />,
        about: (_p, store, prep) => <AboutSection store={store} prepMinutes={prep} />,
    },
    butcher: {
        hero: (p, store, prep) => (
            <ButcherHero
                store={store}
                headline={p.headline}
                bannerUrl={p.bannerUrl}
                nextCollection={p.nextCollection}
                prepMinutes={prep}
                pick={p.featured[0] ?? null}
                howItWorks={p.sections.includes('howItWorks')}
            />
        ),
        departments: (_p, store) => <MeatTiles store={store} />,
        featured: (p, store) => <ButcherPicks store={store} products={p.featured} />,
        howItWorks: (_p, _store, prep) => <ButcherSteps prepMinutes={prep} />,
        shops: (p) => <ShopsSection shops={p.shops} />,
        about: (_p, store, prep) => <AboutSection store={store} prepMinutes={prep} />,
    },
};

/** The store's home page in its template (Fresh Market or Butcher's Block): the sections the store turned on, in its order. */
export default function StoreHome(props: Props) {
    const store = useStore();

    if (!store) {
        return null;
    }

    const { sections, shops } = props;
    const draw = SECTIONS[templateOf(store.template)];
    const prepMinutes = shops.find((shop) => shop.id === store.shop?.id)?.prepMinutes ?? shops[0]?.prepMinutes ?? null;

    return (
        <StoreLayout title={store.name} active="home">
            {!sections.includes('hero') && <h1 className="sr-only">{store.name}</h1>}
            <div className="flex flex-col gap-10 sm:gap-14 lg:gap-[72px]">
                {sections.map((section) => (
                    <div key={section} className="empty:hidden">
                        {draw[section]?.(props, store, prepMinutes)}
                    </div>
                ))}
            </div>
        </StoreLayout>
    );
}
