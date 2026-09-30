import { type Paginated } from '@/components/shared/data-table';

/** Module 4.3 props (App\Domain\Pricing\Queries, App\Domain\Promotions\Queries). */
export interface Option {
    value: string;
    label: string;
}

export interface Shop {
    id: string;
    name: string;
    code: string;
    isActive: boolean;
}

export interface ShopPriceCell {
    price: string;
    validTo: string | null;
    changedAt: 'shop' | 'portal';
}

export interface PriceListRow {
    id: string;
    name: string;
    sku: string | null;
    sellPrice: string;
    shopPrices: Record<string, ShopPriceCell | null>;
    scheduled: number;
}

export interface PriceIndexProps {
    products: Paginated<PriceListRow>;
    shops: Shop[];
    filters: { filter: 'all' | 'own'; department: string | null };
    departments: Option[];
    counts: { products: number; withOwnPrice: number; livePrices: number; scheduled: number };
    restrictedShop: string | null;
    canSetShopPrices: boolean;
}

export type PriceStatus = 'live' | 'scheduled' | 'ended' | 'cancelled';

export interface PriceRow {
    id: string;
    branchId: string | null;
    shop: string | null;
    unitId: string | null;
    unit: string | null;
    price: string;
    validFrom: string;
    validTo: string | null;
    status: PriceStatus;
    changedAt: 'shop' | 'portal';
    updatedAt: string | null;
}

export interface ShopPrices extends Shop {
    current: PriceRow | null;
    unitPrices: PriceRow[];
    scheduled: PriceRow[];
}

export interface ProductPricesProps {
    product: { id: string; name: string; sku: string | null; sellPrice: string; costPrice: string; isActive: boolean };
    units: { id: string; name: string; sellPrice: string }[];
    shops: ShopPrices[];
    history: PriceRow[];
    historyLimited: boolean;
    restrictedShop: string | null;
    canSetShopPrices: boolean;
    canSetEveryShop: boolean;
}

export interface PriceChangeRow {
    id: string;
    reference: string;
    name: string;
    shop: string | null;
    status: string | null;
    source: string;
    reason: string;
    effectiveAt: string | null;
    createdAt: string | null;
    lines: number;
}

export interface PriceChangePreview {
    id: string;
    reference: string;
    name: string;
    status: string | null;
    shop: string | null;
    reason: string;
    lines: {
        id: string;
        productId: string;
        product: string;
        oldPrice: string;
        newPrice: string;
        changePercent: string | null;
        where: string | null;
        status: string | null;
        businessPriceNow: string | null;
    }[];
    limited: boolean;
}

export interface PriceChangesProps {
    batches: Paginated<PriceChangeRow>;
    filters: { status: string | null };
    preview: PriceChangePreview | null;
}

export type OfferStatus = 'live' | 'scheduled' | 'ended';

export interface PromotionRow {
    id: string;
    name: string;
    type: string | null;
    typeLabel: string;
    deal: string;
    on: string;
    scopeLabel: string;
    branchId: string | null;
    shop: string | null;
    from: string;
    to: string | null;
    times: string | null;
    status: OfferStatus;
    couponCode: string | null;
    redemptions: number;
    isGroupOffer: boolean;
    canEdit: boolean;
}

export interface PromotionIndexProps {
    promotions: Paginated<PromotionRow>;
    filters: { status: 'all' | OfferStatus; shop: string };
    shops: Option[];
    counts: { live: number; scheduled: number; shopOnly: number; ended: number };
    restrictedShop: string | null;
    canCreate: boolean;
}

export type PromotionItemValues = {
    id: string | null;
    scope: 'product' | 'category' | 'department';
    target_id: string;
    group_no: string;
    quantity: string;
    is_excluded: boolean;
};

export type PromotionValues = {
    name: string;
    type: string;
    scope: string;
    target_id: string;
    percent: string;
    amount_off: string;
    deal_price: string;
    buy_quantity: string;
    get_quantity: string;
    min_quantity: string;
    priority: string;
    allow_stack: boolean;
    is_exclusive: boolean;
    max_redemptions_per_sale: string;
    max_redemptions_total: string;
    requires_coupon: boolean;
    coupon_code: string;
    branch_id: string;
    is_hfss_safe: boolean;
    effective_from: string;
    effective_to: string;
    time_from: string;
    time_to: string;
    is_active: boolean;
    items: PromotionItemValues[];
};

export interface PromotionFormProps {
    promotion:
        | (PromotionValues & {
              id: string;
              status: OfferStatus;
              deal: string;
              isGroupOffer: boolean;
              redemptions: number;
              updatedAt: string | null;
          })
        | null;
    options: { types: Option[]; products: Option[]; categories: Option[]; departments: Option[]; shops: Option[] };
    restrictedShop: string | null;
    canEdit: boolean;
    today: string;
}
