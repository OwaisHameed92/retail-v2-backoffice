/** Matches `App\Domain\Admin\Enums\TradingPeriod`. */
export type TradingPeriod = 'today' | 'yesterday' | 'last7Days' | 'last30Days' | 'thisMonth' | 'lastMonth' | 'custom';

/** Matches `App\Domain\Admin\Enums\TradingCompare`. */
export type TradingCompare = 'previousPeriod' | 'sameLastWeek' | 'sameLastYear' | 'none';

export interface Option<T extends string> {
    value: T;
    label: string;
}

/** `TradingFilters::toArray()`: trading days are Europe/London dates "Y-m-d". */
export interface TradingFilters {
    period: TradingPeriod;
    from: string;
    to: string;
    compare: TradingCompare;
    company: string | null;
    branch: string | null;
    today: string;
}

/** `TradingContext::for()`: the drill-down. */
export interface TradingContext {
    company: { id: string; name: string } | null;
    branch: { id: string; name: string } | null;
    branches: { id: string; name: string; code: string; active: boolean }[];
}

/** A headline tile (`TradingKpis`): money as decimal strings, change as "12.5" (%), null = "—". */
export interface TradingFigure {
    value: string | null;
    previous: string | null;
    change: string | null;
    series: number[];
    secondary: string | null;
}

/** `SalesTotals::toArray()`. */
export interface SalesTotals {
    gross: string;
    net: string;
    vat: string;
    transactions: number;
    refundCount: number;
    refundGross: string;
    refundNet: string;
    discount: string;
    promo: string;
    coupon: string;
    cost: string;
    containerDeposits: string;
    takings: string;
    voidCount: number;
    voidTotal: string;
    averageBasketExVat: string | null;
    averageBasketIncVat: string | null;
    manualDiscount: string;
    grossProfit: string | null;
}

export interface DayPoint {
    day: string;
    net: string;
    gross: string;
    transactions: number;
    compareDay: string | null;
    compareNet: string | null;
    compareGross: string | null;
    compareTransactions: number | null;
}

export interface HourPoint {
    hour: number;
    net: string | null;
    gross: string | null;
    transactions: number | null;
    compareNet: string | null;
    compareTransactions: number | null;
}

export interface TenderRow {
    paymentTypeId: string;
    name: string;
    amount: string;
    payments: number;
    refunds: string;
}

export interface VatRow {
    vatRateId: string;
    code: string;
    percentage: string;
    net: string;
    vat: string;
    gross: string;
}

/** `LeaderSales` (businesses, shops). */
export interface LeaderRow {
    id: string;
    label: string;
    parentId: string;
    parentLabel: string;
    children: number;
    gross: string;
    net: string;
    transactions: number;
    refundCount: number;
    refundGross: string;
    averageBasketExVat: string | null;
}

/** `GroupSales` (tills of a shop). */
export interface TillRow {
    id: string;
    label: string;
    gross: string;
    net: string;
    vat: string;
    transactions: number;
    refundCount: number;
    refundGross: string;
    takings: string;
    averageBasketExVat: string | null;
}

/** `ProductSales`. */
export interface ProductRow {
    productId: string;
    name: string;
    department: string;
    qty: string;
    net: string;
    gross: string;
    refundNet: string;
}

/** `TradingDashboard::compute()`. */
export interface TradingData {
    generatedAt: string;
    level: 'all' | 'business' | 'shop';
    range: {
        from: string;
        to: string;
        days: number;
        isToday: boolean;
        hour: number;
        compareFrom: string | null;
        compareTo: string | null;
        compareLabel: string;
    };
    activity: {
        companies: number;
        branches: number;
        registers: number;
        rebuiltAt: string | null;
        lastPushAt: string | null;
        pendingDays: number;
    };
    kpis: {
        headline: Record<'gross' | 'net' | 'transactions' | 'averageBasket', TradingFigure>;
        totals: SalesTotals;
        previous: SalesTotals | null;
        changes: Record<'vat' | 'refundGross' | 'discount' | 'takings' | 'voidTotal', string | null> | null;
    };
    daily: DayPoint[] | null;
    hourly: HourPoint[];
    tenders: TenderRow[];
    vat: VatRow[];
    leaders: {
        businesses: LeaderRow[] | null;
        shops: LeaderRow[] | null;
        tills: TillRow[] | null;
        products: ProductRow[] | null;
    };
}

export interface TradingPageProps {
    filters: TradingFilters;
    context: TradingContext;
    periods: Option<TradingPeriod>[];
    compares: Option<TradingCompare>[];
    trading?: TradingData;
}
