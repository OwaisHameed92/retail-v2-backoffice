import { ButcherPicks, MeatTiles } from '@/components/store/butcher-sections';
import {
    AboutSection,
    DepartmentRowsSection,
    DepartmentsSection,
    FeaturedSection,
    HeroSection,
    HowItWorksSection,
    OffersSection,
    ShopsSection,
} from '@/components/store/home-sections';
import { StoreLayout } from '@/components/store/store-layout';
import { templateOf } from '@/components/store/theme';
import {
    type BannerSlide,
    type DepartmentRow,
    type DepartmentTile,
    type FeaturedMode,
    type NextSlot,
    type ShopInfo,
    type StoreInfo,
    type StoreProduct,
    type StoreTemplate,
} from '@/components/store/types';
import { useStore } from '@/components/store/use-store';
import { type ReactNode } from 'react';

type Section = 'hero' | 'departments' | 'featured' | 'howItWorks' | 'shops' | 'about' | 'offers' | 'departmentRows';

interface Props {
    sections: Section[];
    banners: BannerSlide[];
    headline: string | null;
    bannerUrl: string;
    nextCollection: string | null;
    /** OS-12: the first slot with room over the shop's methods. */
    nextSlot?: NextSlot | null;
    featured: StoreProduct[];
    featuredMode: FeaturedMode;
    offers: StoreProduct[];
    departmentRows: DepartmentRow[];
    departmentTiles: DepartmentTile[];
    aboutText: string | null;
    shops: ShopInfo[];
}

type Draw = (props: Props, store: StoreInfo, prepMinutes: number | null) => ReactNode;

/** The banners, or the first one built from the old single banner props. */
function slidesOf(p: Props): BannerSlide[] {
    return p.banners.length > 0
        ? p.banners
        : [{ id: 'main', photoUrl: p.bannerUrl, headline: p.headline, text: null, buttonLabel: null, href: null, external: false }];
}

/** Sections every template draws the same way (their words follow the template). */
const SHARED: Record<'shops' | 'about' | 'offers' | 'departmentRows', Draw> = {
    shops: (p, store) => <ShopsSection shops={p.shops} store={store} />,
    about: (p, store, prep) => <AboutSection store={store} prepMinutes={prep} text={p.aboutText} />,
    offers: (p, store) => <OffersSection products={p.offers} store={store} />,
    departmentRows: (p) => <DepartmentRowsSection rows={p.departmentRows} />,
};

/** How each template draws each home section. */
const SECTIONS: Record<StoreTemplate, Record<Section, Draw>> = {
    freshMarket: {
        hero: (p, store, prep) => (
            <HeroSection
                store={store}
                slides={slidesOf(p)}
                nextSlot={p.nextSlot ?? (p.nextCollection ? { method: 'collection', when: p.nextCollection } : null)}
                prepMinutes={prep}
                howItWorks={p.sections.includes('howItWorks')}
                template="freshMarket"
            />
        ),
        departments: (p, store) => <DepartmentsSection store={store} tiles={p.departmentTiles} />,
        featured: (p, store) => <FeaturedSection store={store} products={p.featured} mode={p.featuredMode} />,
        howItWorks: (_p, store, prep) => <HowItWorksSection store={store} prepMinutes={prep} />,
        ...SHARED,
    },
    butcher: {
        hero: (p, store, prep) => (
            <HeroSection
                store={store}
                slides={slidesOf(p)}
                nextSlot={p.nextSlot ?? (p.nextCollection ? { method: 'collection', when: p.nextCollection } : null)}
                prepMinutes={prep}
                howItWorks={p.sections.includes('howItWorks')}
                template="butcher"
            />
        ),
        departments: (p, store) => <MeatTiles store={store} tiles={p.departmentTiles} />,
        featured: (p, store) => <ButcherPicks store={store} products={p.featured} mode={p.featuredMode} />,
        howItWorks: (_p, store, prep) => <HowItWorksSection store={store} prepMinutes={prep} template="butcher" />,
        ...SHARED,
    },
};

/**
 * The store's home page in its template (Fresh Market or Butcher's Block): the sections the store turned on, in its
 * order (OS-8: banners carousel, department photo tiles, featured products, "On offer" and department rows).
 */
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
            <div className="flex flex-col gap-10 sm:gap-12 lg:gap-14">
                {sections.map((section) => (
                    <div key={section} className="empty:hidden">
                        {draw[section]?.(props, store, prepMinutes)}
                    </div>
                ))}
            </div>
        </StoreLayout>
    );
}
