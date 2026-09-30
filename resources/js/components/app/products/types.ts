import { type Paginated } from '@/components/shared/data-table';

export interface Option {
    value: string;
    label: string;
}

/** CatalogueOptions::all() */
export interface CatalogueOptions {
    departments: (Option & { isActive: boolean; defaultVatRateId: string | null })[];
    categories: (Option & { departmentId: string; parentId: string | null; isActive: boolean; defaultVatRateId: string | null; ageRuleDefault: string | null })[];
    vatRates: (Option & { code: string; percentage: string; isDefault: boolean })[];
    units: (Option & { code: string; isActive: boolean })[];
    ageRules: Option[];
}

/** ProductList row */
export interface ProductRow {
    id: string;
    name: string;
    sku: string | null;
    brand: string | null;
    department: string | null;
    category: string | null;
    sellPrice: string;
    costPrice: string;
    vat: string | null;
    barcode: string | null;
    barcodeCount: number;
    ageRestricted: boolean;
    isActive: boolean;
    updatedAt: string | null;
}

export interface ProductIndexProps {
    products: Paginated<ProductRow>;
    filters: { status: 'active' | 'archived' | 'all'; department: string | null; category: string | null };
    counts: { active: number; archived: number; departments: number; categories: number };
    options: CatalogueOptions;
    canManage: boolean;
}

export type BarcodeValue = {
    id: string | null;
    barcode: string;
    pack_qty: string;
    is_primary: boolean;
};

export type UnitValue = {
    id: string | null;
    unit_id: string;
    conversion_factor: string;
    sell_price_inc_vat: string;
    cost: string;
    is_default_sell_unit: boolean;
    is_purchase_unit: boolean;
    is_default_purchase_unit: boolean;
};

/** ProductForm values: SaveProductRequest fields. */
export type ProductValues = {
    name: string;
    short_name: string;
    receipt_name: string;
    sku: string;
    brand: string;
    description: string;
    department_id: string;
    category_id: string;
    sub_category_id: string;
    vat_rate_id: string;
    unit_type: string;
    unit_code: string;
    is_weighed: boolean;
    is_open_price: boolean;
    sell_price: string;
    cost_price: string;
    trade_price: string;
    pmp_price: string;
    track_stock: boolean;
    min_stock_qty: string;
    max_stock_qty: string;
    reorder_qty: string;
    negative_stock_mode: string;
    bin_location: string;
    tracks_expiry_dates: boolean;
    age_rule: string;
    max_qty_per_sale: string;
    max_qty_reason: string;
    is_alcohol: boolean;
    abv_percent: string;
    volume_ml: string;
    is_tobacco: boolean;
    is_lottery: boolean;
    is_knife: boolean;
    is_banned: boolean;
    is_hfss: boolean;
    vape_duty_applies: boolean;
    is_deposit_item: boolean;
    deposit_amount: string;
    commodity_code: string;
    net_mass_kg: string;
    tile_colour_hex: string;
    tile_emoji: string;
    tile_position: string;
    is_active: boolean;
    barcodes: BarcodeValue[];
    units: UnitValue[];
};

export interface ProductFormProps {
    product: {
        id: string;
        name: string;
        isActive: boolean;
        archivedAt: string | null;
        createdAt: string | null;
        updatedAt: string | null;
        lastChangedBy: string;
        nearestExpiryDate: string | null;
    } | null;
    values: ProductValues;
    options: CatalogueOptions;
    canManage: boolean;
}

export type SetValue = <K extends keyof ProductValues>(key: K, value: ProductValues[K]) => void;

export interface SectionProps {
    data: ProductValues;
    setData: SetValue;
    errors: Partial<Record<string, string>>;
    options: CatalogueOptions;
}

/** CategoryTree nodes */
export interface CategoryNode {
    id: string;
    name: string;
    departmentId: string;
    parentId: string | null;
    position: number;
    colourHex: string;
    isActive: boolean;
    isVisibleOnTill: boolean;
    ageRuleDefault: string;
    defaultVatRateId: string | null;
    negativeStockMode: string | null;
    productCount: number;
    children?: CategoryNode[];
}

export interface DepartmentNode {
    id: string;
    name: string;
    position: number;
    colourHex: string;
    isActive: boolean;
    isVisibleOnTill: boolean;
    showInReport: boolean;
    defaultVatRateId: string | null;
    productCount: number;
    categories: CategoryNode[];
}
