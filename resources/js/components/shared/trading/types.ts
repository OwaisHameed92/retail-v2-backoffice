/**
 * Shapes shared by the trading dashboards (admin 3.2 `TradingDashboard::compute()`, business 3.3
 * `BusinessDashboard::compute()`), built on the same server classes (`DashboardKpis`, `DashboardSeries`).
 */

/** Matches `App\Domain\Reporting\Enums\TradingPeriod`. */
export type TradingPeriod = 'today' | 'yesterday' | 'thisWeek' | 'last7Days' | 'last30Days' | 'thisMonth' | 'lastMonth' | 'custom';

/** Matches `App\Domain\Reporting\Enums\TradingCompare`. */
export type TradingCompare = 'previousPeriod' | 'sameLastWeek' | 'sameLastYear' | 'none';

export interface Option<T extends string> {
    value: T;
    label: string;
}

/** The date part of both panels' filters (trading days are "Y-m-d" dates in the profile's time zone). */
export interface PeriodFilters {
    period: TradingPeriod;
    from: string;
    to: string;
    compare: TradingCompare;
    today: string;
}

/** A headline tile (`DashboardKpis`): money as decimal strings, change as "12.5" (%), null = "—". */
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
    staffDiscount: string;
    orderDeposits: string;
    charity: string;
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

/** `GroupSales` (shops or tills). */
export interface GroupRow {
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
    category?: string;
    qty: string;
    net: string;
    gross: string;
    refundNet: string;
}

export interface SalesRange {
    from: string;
    to: string;
    days: number;
    isToday: boolean;
    /** Today's trading day: a range that ends on it has a partial last day ("so far"). */
    today: string;
    hour: number;
    compareFrom: string | null;
    compareTo: string | null;
    compareLabel: string;
}

export interface SalesKpis {
    headline: Record<'gross' | 'net' | 'transactions' | 'averageBasket', TradingFigure>;
    totals: SalesTotals;
    previous: SalesTotals | null;
    changes: Record<'vat' | 'refundGross' | 'discount' | 'takings' | 'voidTotal', string | null> | null;
}

/** What the shared cards read: the common part of both dashboards. */
export interface SalesDashboardData {
    generatedAt: string;
    range: SalesRange;
    kpis: SalesKpis;
    daily: DayPoint[] | null;
    hourly: HourPoint[];
    tenders: TenderRow[];
    vat: VatRow[];
}

/** "Updated N min ago" (`AdminTradingReport::activity()` / `ShopFreshness::for()`). */
export interface FreshnessInfo {
    lastPushAt: string | null;
    rebuiltAt: string | null;
    pendingDays: number;
    shops?: ShopFreshness[];
}

export interface ShopFreshness {
    id: string;
    name: string;
    lastPushAt: string | null;
    lastContactAt: string | null;
    state: 'live' | 'recent' | 'stale' | 'never';
}
