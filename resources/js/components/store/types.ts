/** Props the public online store's pages share (OS-2, App\Http\Support\Store\StorePages). */
import { type CountryProfile } from '@/types';

export interface StoreDepartment {
    id: string;
    name: string;
    count: number;
    url: string;
}

export interface StoreInfo {
    name: string;
    initials: string;
    logoUrl: string | null;
    brand: string;
    takingOrders: boolean;
    shop: { id: string; name: string } | null;
    shopCount: number;
    departments: StoreDepartment[];
    urls: { home: string; search: string; basket: string; basketAdd: string; checkout: string };
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
}

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
    sort: 'name' | 'priceAsc' | 'priceDesc';
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
