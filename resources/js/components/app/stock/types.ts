/** Matches App\Domain\Stock\Queries\* and the StockController / StockTakeController props (module 5.1). */

import { type TableMeta } from '@/components/shared/data-table';

export interface Option {
    value: string;
    label: string;
}

export type StockStatus = 'ok' | 'low' | 'out' | 'negative';

export interface StockFiltersState {
    shop: string | null;
    shopLocked: boolean;
    search: string | null;
    status: 'low' | 'out' | 'negative' | null;
    department: string | null;
    supplier: string | null;
    product: string | null;
    type: string | null;
    from: string;
    to: string;
    within: number;
    takeStatus?: string | null;
}

export interface StockOptions {
    shops: Option[];
    departments: Option[];
    suppliers: Option[];
}

export interface StockRow {
    id: string;
    productId: string;
    shopId: string | null;
    name: string;
    sku: string;
    department: string | null;
    onHand: string;
    reserved: string;
    available?: string;
    lowAt?: string;
    max?: string | null;
    shops?: number;
    lowShops?: number;
    outShops?: number;
    negativeShops?: number;
    cost: string | null;
    value: string | null;
    status: StockStatus;
}

export interface StockSummary {
    lines: number;
    products: number;
    units: string;
    value: string;
    costed: number;
    low: number;
    out: number;
    negative: number;
}

export interface StockIndexProps {
    filters: StockFiltersState;
    summary: StockSummary;
    stock: { data: StockRow[]; meta: TableMeta };
    options: StockOptions;
    hasStock: boolean;
}

export interface MovementRow {
    id: string;
    at: string;
    productId: string;
    product: string;
    sku: string;
    shop: string;
    type: string | null;
    typeLabel: string;
    group: string | null;
    qty: string;
    before: string;
    after: string;
    unitCost: string;
    value: string;
    reason: string | null;
    note: string | null;
    refType: string | null;
    refId: string | null;
    staff: string | null;
}

export interface MovementSummaryRow {
    group: string;
    label: string;
    count: number;
    qty: string;
    value: string;
}

export interface MovementsProps {
    movements: { data: MovementRow[]; older: string | null; newer: string | null; perPage: number };
    summary: MovementSummaryRow[];
    product: { id: string; name: string; sku: string } | null;
    typeOptions: Option[];
    filters: StockFiltersState;
    options: StockOptions;
    canViewSales: boolean;
}

export interface ProductLine {
    id: string;
    shopId: string;
    shop: string;
    onHand: string;
    reserved: string;
    available: string;
    lowAt: string;
    reorderPoint: string | null;
    min: string | null;
    max: string | null;
    status: StockStatus;
    fifoValue: string;
    costValue: string;
    basis: ValuationBasis;
}

export type ValuationBasis = 'fifo' | 'mixed' | 'cost' | 'none';

export interface ProductStockProps {
    product: {
        id: string;
        name: string;
        sku: string;
        costPrice: string;
        trackStock: boolean;
        tracksExpiryDates: boolean;
        negativeStockMode: string | null;
        minStockQty: string | null;
        maxStockQty: string | null;
        reorderQty: string | null;
        isActive: boolean;
    };
    lines: ProductLine[];
    totals: { onHand: string; fifoValue: string; costValue: string };
    layers: { id: string; shop: string; receivedAt: string | null; qty: string; unitCost: string; value: string; source: string | null }[];
    batches: { id: string; shop: string; batch: string | null; expiry: string | null; receivedAt: string | null; qty: string; unitCost: string }[];
    recent: MovementRow[];
    filters: StockFiltersState;
    canManage: boolean;
}

export interface TakeHeader {
    id: string;
    reference: string;
    name: string;
    shop: string;
    scope: string | null;
    status: string | null;
    startedAt: string | null;
    approvedAt: string | null;
    cancelledAt: string | null;
    startedBy: string | null;
    isHighValue: boolean;
}

export interface TakeRow extends TakeHeader {
    lines: number;
    counted: number;
    varianceQty: string;
    varianceCost: string;
}

export interface TakesProps {
    takes: { data: TakeRow[]; meta: TableMeta };
    filters: StockFiltersState;
    options: StockOptions;
}

export interface TakeLine {
    id: string;
    productId: string;
    name: string;
    expected: string;
    counted: string | null;
    varianceQty: string;
    unitCost: string;
    varianceCost: string;
    recount: boolean;
    countedBy: string | null;
    countedAt: string | null;
}

export interface TakeProps {
    take: TakeHeader & {
        approvedBy: string | null;
        isBlind: boolean;
        countUncountedAsZero: boolean;
        note: string | null;
        lines: number;
        counted: number;
        recount: number;
        varianceQty: string;
        varianceCost: string;
        gains: string;
        losses: string;
        snapshotValue: string;
    };
    lines: { data: TakeLine[]; meta: TableMeta };
    view: 'all' | 'variances';
}

export interface ValuationGroup {
    id: string;
    name: string;
    lines: number;
    fifo: string;
    cost: string;
}

export interface ValuationProps {
    totals: {
        lines: number;
        fifoValue: string;
        costValue: string;
        difference: string;
        basis: Record<ValuationBasis, number>;
        layerRows: number;
    };
    byShop: ValuationGroup[];
    byDepartment: ValuationGroup[];
    top: {
        id: string;
        productId: string;
        name: string;
        sku: string;
        shop: string;
        onHand: string;
        fifoValue: string;
        costValue: string;
        fifoQty: string;
        basis: ValuationBasis;
    }[];
    filters: StockFiltersState;
    options: StockOptions;
}

export interface Bucket {
    count: number;
    qty: string;
    value: string;
}

export interface BatchRow {
    id: string;
    productId: string;
    name: string;
    sku: string;
    shop: string;
    batch: string | null;
    expiry: string | null;
    daysLeft: number | null;
    qty: string;
    unitCost: string;
    value: string;
    expired: boolean;
}

export interface ExpiryProps {
    batches: { data: BatchRow[]; meta: TableMeta };
    buckets: { expired: Bucket; week: Bucket; month: Bucket };
    checks: {
        id: string;
        productId: string;
        name: string;
        shop: string;
        action: 'ok' | 'reduced' | 'wasted' | null;
        markdownPercent: string | null;
        note: string | null;
        checkedAt: string | null;
        checkedBy: string | null;
    }[];
    wastage: { rows: { type: string; label: string; count: number; qty: string; value: string }[]; qty: string; value: string };
    filters: StockFiltersState;
    options: StockOptions;
}
