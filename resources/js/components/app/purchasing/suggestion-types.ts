import { type PurchasingShared } from './types';

/** Flags set by `ReorderCalculator` (module 6.4). */
export type SuggestionFlag =
    | 'outOfStock'
    | 'negativeStock'
    | 'runsOut'
    | 'shortLife'
    | 'wasteRisk'
    | 'seasonal'
    | 'spike'
    | 'slowing'
    | 'overstock'
    | 'noHistory'
    | 'capped';

/** One line of `ReorderSuggestions` (quantities are 4 dp strings, money 2 dp). */
export interface SuggestionLine {
    key: string;
    groupKey: string;
    shopId: string;
    shopName: string;
    supplierId: string;
    supplierName: string;
    productId: string;
    name: string;
    sku: string | null;
    supplierSku: string | null;
    department: string | null;
    caseQty: number;
    unitCost: string;
    onHand: string;
    onOrder: string;
    inTransit: string;
    minLevel: string | null;
    maxLevel: string | null;
    rate: string;
    coverDays: string | null;
    forecast: string;
    horizonDays: number;
    shelfLifeDays: number | null;
    events: string[];
    suggestedCases: number;
    suggestedUnits: string;
    suggestedCost: string;
    method: 'forecast' | 'levels' | 'none';
    flags: SuggestionFlag[];
    reasons: string[];
}

export interface SuggestionGroup {
    key: string;
    shopId: string;
    shopName: string;
    supplierId: string;
    supplierName: string;
    leadDays: number;
    leadBasis: 'shop' | 'business' | 'supplier' | 'default';
    leadSamples: number;
    reviewDays: number;
    minimumOrder: string | null;
}

export type SuggestionView = 'order' | 'attention' | 'all';

export interface SuggestionsProps extends PurchasingShared {
    filters: { shop: string | null; supplier: string | null; department: string | null; view: SuggestionView; q: string | null };
    lines: SuggestionLine[];
    groups: SuggestionGroup[];
    truncated: boolean;
    /** Lines worth a look whatever the view, the most pressing first (at most 8). */
    notable: SuggestionLine[];
    stats: { lines: number; orders: number; cost: string; attention: number };
    departments: { id: string; name: string }[];
    settings: { historyWeeks: number; reviewDays: number };
    ai: { available: boolean };
}

/** Cases chosen per line key (edited on the page, suggested by default). */
export type Quantities = Record<string, number>;
