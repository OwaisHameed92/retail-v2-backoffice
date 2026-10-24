/** Props the public online store's pages share (OS-2, App\Http\Support\Store\StorePages). */
import { type CountryProfile } from '@/types';

export interface StoreDepartment {
    id: string;
    name: string;
    count: number;
    url: string;
}

/** The store's look (App\Domain\OnlineStore\Enums\StoreTemplate). Unknown values are drawn as Fresh Market. */
export type StoreTemplate = 'freshMarket' | 'butcher';

/** The store's colours from its brand colour (App\Domain\OnlineStore\Support\BrandPalette, WCAG AA). */
export interface StorePalette {
    brand: string;
    onBrand: string;
    brandHover: string;
    brandText: string;
    brandDeep: string;
    brandSoft: string;
    brandTint: string;
    highlight: string;
    onHighlight: string;
    highlightSoft: string;
}

export interface StoreInfo {
    name: string;
    initials: string;
    logoUrl: string | null;
    brand: string;
    palette?: StorePalette;
    template: StoreTemplate;
    takingOrders: boolean;
    shop: {
        id: string;
        name: string;
        address: string;
        phone: string | null;
        hours?: OpeningDay[];
        openNow?: { open: boolean; label: string };
    } | null;
    shopCount: number;
    /** The shops to choose from (only when there is more than one). */
    shops?: { id: string; name: string }[];
    departments: StoreDepartment[];
    urls: { home: string; search: string; basket: string; basketAdd: string; checkout: string; shop?: string };
}

export interface BasketSummary {
    count: number;
    total: string;
    estimated: boolean;
    notices: string[];
}

export interface StoreProduct {
    id: string;
    name: string;
    description: string | null;
    photoUrl: string | null;
    departmentId: string | null;
    price: string;
    isWeighed: boolean;
    /** "1", or "0.25" (kg) for weighed products. */
    step: string;
    max: string;
    orderable: boolean;
    url: string;
    /** OS-8: the till offer on it today ("3 for £2.00"), taken off at the till. */
    offer?: string | null;
    /** "500 g", "750 ml" from the till's pack size. */
    size?: string | null;
    unitPrice?: { amount: string; unit: 'kg' | 'litre' } | null;
}

/** One home banner (App\Domain\OnlineStore\Queries\StoreBanners::slides). */
export interface BannerSlide {
    id: string;
    photoUrl: string;
    headline: string | null;
    text: string | null;
    buttonLabel: string | null;
    href: string | null;
    external: boolean;
}

export interface DepartmentTile extends StoreDepartment {
    photoUrl: string | null;
}

export interface DepartmentRow extends StoreDepartment {
    products: StoreProduct[];
}

export type FeaturedMode = 'bestSellers' | 'handPicked' | 'newest';

export interface BasketLine {
    product: StoreProduct;
    quantity: string;
    lineTotal: string;
    updateUrl: string;
    removeUrl: string;
}

export interface OpeningDay {
    day: string;
    hours: { open: string; close: string } | null;
}

export interface ShopInfo {
    id: string;
    name: string;
    address: string;
    phone: string | null;
    prepMinutes: number;
    hours: OpeningDay[];
}

export interface Pagination {
    page: number;
    lastPage: number;
    total: number;
}

export interface ListFilters {
    sort: 'name' | 'priceAsc' | 'priceDesc' | 'newest';
    stock: boolean;
    min: string | null;
    max: string | null;
}

/** Every store page's props: the shared frame plus the page's own. */
export interface StorePageProps {
    store?: StoreInfo;
    basket?: BasketSummary;
    country?: CountryProfile;
    flash?: { success: string | null; error: string | null };
    errors: Record<string, string>;
    [key: string]: unknown;
}
