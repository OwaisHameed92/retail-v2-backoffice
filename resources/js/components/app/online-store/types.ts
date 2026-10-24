import { type Paginated } from '@/components/shared/data-table/types';

/** Matches App\Domain\OnlineStore\Queries\StoreBuilderPage::for(). */
export type TemplateKey = 'freshMarket' | 'butcher';

export type StoreSectionValue = {
    key: string;
    enabled: boolean;
};

export interface StoreSettings {
    slug: string;
    template: TemplateKey;
    headline: string | null;
    brandColour: string | null;
    logoUrl: string | null;
    bannerUrl: string | null;
    sections: StoreSectionValue[];
    orderEmail: string | null;
    isLive: boolean;
    takingOrders: boolean;
    liveAt: string | null;
    address: string;
    /** OS-8: "About us" text (null = the template's), featured products setting, department rows. */
    aboutText: string | null;
    featuredMode: FeaturedModeKey;
    featuredProductIds: string[];
    homeDepartments: string[];
}

export type FeaturedModeKey = 'bestSellers' | 'handPicked' | 'newest';

export type BannerLinkKey = 'none' | 'department' | 'product' | 'search' | 'url';

/** One home banner (App\Domain\OnlineStore\Queries\StoreBanners::forBuilder). */
export interface StoreBanner {
    id: string;
    position: number;
    photoUrl: string | null;
    headline: string | null;
    text: string | null;
    buttonLabel: string | null;
    linkType: BannerLinkKey;
    linkTarget: string | null;
    linkLabel: string | null;
    isOn: boolean;
}

/** A product from the picker (App\Domain\OnlineStore\Queries\StoreProductSearch). */
export type PickerProduct = {
    id: string;
    name: string;
    price?: string;
    isWeighed?: boolean;
    department?: string | null;
    photoUrl: string | null;
};

/** The store's look (read only here: Switch & Save sets it). */
export interface TemplateOption {
    value: TemplateKey;
    label: string;
    for: string;
    photo: string;
}

export interface BuilderProps {
    /** Null until Switch & Save switches the online store on. */
    store: StoreSettings | null;
    template: TemplateOption | null;
    colours: { name: string; hex: string }[];
    sectionOptions: { key: string; label: string; hint: string }[];
    addressPrefix: string;
    /** OSA-14: the business email used while the order email is empty (null: no order emails). */
    orderEmailFallback: string | null;
    checklist: { collectionShops: number; productsShown: number | null; productsShop: string | null };
    canEdit: boolean;
    banners: StoreBanner[];
    bannerMax: number;
    linkOptions: { value: BannerLinkKey; label: string }[];
    featuredOptions: { value: FeaturedModeKey; label: string; hint: string }[];
    featuredMax: number;
    homeDepartmentsMax: number;
    aboutMax: number;
    aboutPlaceholder: string | null;
    departments: { id: string; name: string }[];
    pickedProducts: PickerProduct[];
}

/** Matches App\Domain\OnlineStore\Queries\CollectionShopsPage::for(). */
export interface ShopDay {
    day: 'monday' | 'tuesday' | 'wednesday' | 'thursday' | 'friday' | 'saturday' | 'sunday';
    open: string | null;
    close: string | null;
}

export interface CollectionShop {
    id: string;
    name: string;
    code: string;
    town: string | null;
    saved: boolean;
    enabled: boolean;
    hoursSource: 'saved' | 'calendar' | 'default';
    days: ShopDay[];
    prepMinutes: number;
    slotMinutes: number;
    ordersPerSlot: number;
    /** Audit OSA-13: closed days and special hours from today on. */
    closures?: ShopClosure[];
}

export interface ShopsProps {
    hasStore: boolean;
    shops: CollectionShop[];
    slotOptions: number[];
}

/** Audit OSA-13: a closed day or special hours (App\Domain\OnlineStore\Models\OnlineStoreClosure). */
export interface ShopClosure {
    id: string;
    date: string;
    opensAt: string | null;
    closesAt: string | null;
    reason: string | null;
}

/** OS-9: matches App\Domain\OnlineStore\Queries\DeliveryPaymentsPage::for(). */
export interface DeliveryShop {
    id: string;
    name: string;
    code: string;
    town: string | null;
    postcode: string | null;
    collects: boolean;
    enabled: boolean;
    areas: string[];
    fee: string;
    freeOver: string | null;
    minimum: string | null;
    days: ShopDay[];
    hoursSaved: boolean;
    slotMinutes: number;
    ordersPerSlot: number;
    leadMinutes: number;
}

export type PaymentValue = 'payAtShop' | 'cardOnline' | 'payOnDelivery';

export interface MethodPayments {
    chosen: PaymentValue[];
    options: { value: PaymentValue; label: string }[];
}

export interface DeliveryPaymentsProps {
    hasStore: boolean;
    canEditPayments: boolean;
    shops: DeliveryShop[];
    slotOptions: number[];
    payments: { cardReady: boolean; collection: MethodPayments; delivery: MethodPayments } | null;
    /** Only whether the DNA details are saved and their last 4 characters: never the details themselves. */
    dna: { configured: boolean; clientId: string | null; terminalId: string | null; secret: string | null; testMode: boolean } | null;
}

/** "30 minutes", "1 hour", "1 h 30 min". */
export function minutesLabel(m: number): string {
    return m < 60 ? `${m} minutes` : m % 60 === 0 ? `${m / 60} ${m === 60 ? 'hour' : 'hours'}` : `${Math.floor(m / 60)} h ${m % 60} min`;
}

/** Matches App\Domain\OnlineStore\Queries\OnlineProductsPage::for(). */
export type ProductStatusFilter = 'all' | 'shown' | 'notShown' | 'hidden';

export interface OnlineProductRow {
    id: string;
    name: string;
    sku: string | null;
    department: string | null;
    price: string | null;
    isWeighed: boolean;
    photoUrl: string | null;
    /** IMG-1: the picture the till sent, shown in the store when there is no online photo. */
    tillPhotoUrl: string | null;
    description: string | null;
    tillDescription: string | null;
    /** OS-8 (audit OSA-3): the online ingredients and allergens text, and what the tills hold. */
    allergenInfo: string | null;
    tillAllergens: string | null;
    hidden: boolean;
    shown: boolean;
    reasons: string[];
}

export interface ProductsProps {
    products: Paginated<OnlineProductRow>;
    shops: { id: string; name: string; collects: boolean }[];
    filters: { shop: string | null; status: ProductStatusFilter; department: string | null };
    departments: { id: string; name: string }[];
    counts: { total: number; shown: number; hidden: number };
    hasStore: boolean;
    canEdit: boolean;
    descriptionMax: number;
}

export const PRODUCTS_ONLY = ['products', 'filters', 'counts'];

export const DAY_NAMES: Record<ShopDay['day'], string> = {
    monday: 'Monday',
    tuesday: 'Tuesday',
    wednesday: 'Wednesday',
    thursday: 'Thursday',
    friday: 'Friday',
    saturday: 'Saturday',
    sunday: 'Sunday',
};

/** Images the store takes (checked again on the server). */
export const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
export const IMAGE_MAX_BYTES = 4 * 1024 * 1024;

/** A problem with a picked file, or null when it can be uploaded. */
export function imageProblem(file: File): string | null {
    if (!IMAGE_TYPES.includes(file.type)) {
        return 'Choose a JPG, PNG or WebP picture.';
    }

    return file.size > IMAGE_MAX_BYTES ? 'Choose a picture of 4 MB or less.' : null;
}
