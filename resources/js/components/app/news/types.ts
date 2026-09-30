import { type NamedOption, type PurchasingStat, type ShopOption } from '@/components/app/purchasing/types';
import { type Paginated } from '@/components/shared/data-table';

/** Matches `News\Queries\NewsPage::KINDS`. */
export type NewsKind = 'titles' | 'deliveries' | 'returns' | 'vouchers';

/** `NewsPage::shared()`. */
export interface NewsShared {
    shops: ShopOption[];
    suppliers: NamedOption[];
    oneShop: boolean;
    /** `manage`: news.manage; `everyShop`: may keep titles for every shop (owner, every-shop manager). */
    can: { manage: boolean; everyShop: boolean };
}

export interface NewsStat extends Omit<PurchasingStat, 'format'> {
    format: 'count' | 'money' | 'percent';
}

/** One list row: common keys plus the kind's own (see the `News\Queries\Lists` classes). */
export interface NewsRow {
    id: string;
    reference: string;
    status: string | null;
    shop?: string | null;
    supplier: string;
    gross: string;
    [key: string]: unknown;
}

export interface NewsIndexProps extends NewsShared {
    kind: NewsKind;
    tabs: Record<NewsKind, number>;
    stats: NewsStat[];
    filters: { shop: string | null; supplier: string | null; status: string | null };
    statuses: string[];
    rows: Paginated<NewsRow>;
}

export interface LinkedProduct {
    id: string;
    name: string;
    sku: string | null;
    barcode: string | null;
    price: string | null;
    vat: string | null;
}

export interface TitleFormProps extends NewsShared {
    title: {
        id: string;
        name: string;
        publisher: string;
        frequency: string;
        supplierId: string;
        coverPrice: string;
        linkedProductId: string;
        linkedBarcode: string;
        shopId: string;
        isActive: boolean;
    } | null;
    defaultShopId: string;
    linkedProduct: LinkedProduct | null;
    search: string | null;
    results: LinkedProduct[];
    frequencies: string[];
}

export interface DeliveryLine {
    id: string;
    title: string;
    qtyIn: number;
    qtySold: number;
    qtyReturned: number;
    qtyUnaccounted: number;
    unitCost: string | null;
    cost: string;
    credit: string;
    sales: string | null;
}

export interface DeliveryShowProps extends NewsShared {
    delivery: {
        id: string;
        date: string;
        status: string | null;
        shop: string | null;
        supplier: string;
        creditPostedAt: string | null;
        notes: string | null;
    };
    lines: DeliveryLine[];
    totals: { qtyIn: number; qtySold: number; qtyReturned: number; cost: string; credit: string; netCost: string; sales: string; margin: string };
}

/** `WeeklySummary::figures()`. */
export interface NewsFigures {
    qtyIn: number;
    qtySold: number;
    qtyReturned: number;
    qtyUnaccounted: number;
    sellThrough: number | null;
    cost: string;
    credit: string;
    netCost: string;
    sales: string;
    margin: string;
    marginPercent: number | null;
    vouchers?: number;
    voucherValue?: string;
}

export type SummaryShopRow = NewsFigures & { shopId: string; shop: string };

export interface SummaryProps extends NewsShared {
    week: { start: string; end: string; previous: string; next: string | null; current: boolean };
    shop: string | null;
    byShop: SummaryShopRow[];
    total: NewsFigures;
    titles: (NewsFigures & { title: string; publisher: string | null })[];
    trend: { week: string; sales: string; margin: string }[];
}
