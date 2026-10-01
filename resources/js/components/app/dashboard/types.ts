import { type FreshnessInfo, type GroupRow, type Option, type PeriodFilters, type ProductRow, type SalesDashboardData, type TradingCompare, type TradingPeriod } from '@/components/shared/trading/types';
import { type YesterdayGlance } from '@/components/app/dashboard/yesterday-card';
import { type ShopsStatus } from '@/components/till-health/types';

/** `BusinessDashboardFilters::toArray()`. */
export interface BusinessFilters extends PeriodFilters {
    branch: string | null;
    till: string | null;
}

/** `BusinessContext::for()`: the shop (switcher's, or a one-shop user's own) and its tills. */
export interface BusinessContext {
    restricted: boolean;
    branch: { id: string; name: string; code: string } | null;
    till: { id: string; label: string } | null;
    tills: { id: string; code: string; name: string; active: boolean }[];
}

/** `LineGroupSales` (departments, categories); `id` null = "Unassigned", "rest" = everything else. */
export interface LineGroupRow {
    id: string | null;
    name: string;
    qty: string;
    net: string;
    gross: string;
}

/** `StaffSales`. */
export interface StaffRow {
    userId: string;
    name: string;
    gross: string;
    net: string;
    transactions: number;
    refundCount: number;
    refundGross: string;
    voidCount: number;
    averageBasketExVat: string | null;
}

/** `OperationsReport`: low stock now, cash variance of shifts closed in the range, web orders ready. */
export interface Operations {
    lowStock: { total: number; byShop: Record<string, number> };
    cash: { variance: string; shifts: number; shortShifts: number };
    ordersReady: number;
}

/** `BusinessDashboard::compute()` + `ShopFreshness::for()`. */
export interface BusinessData extends SalesDashboardData {
    level: 'business' | 'shop' | 'till';
    shops: GroupRow[] | null;
    tills: GroupRow[] | null;
    products: ProductRow[];
    departments: LineGroupRow[];
    categories: LineGroupRow[];
    staff: StaffRow[];
    operations: Operations;
    freshness: FreshnessInfo & { hasData: boolean };
}

export interface BusinessDashboardProps {
    status: ShopsStatus | null;
    canSales: boolean;
    filters: BusinessFilters;
    context: BusinessContext;
    periods: Option<TradingPeriod>[];
    compares: Option<TradingCompare>[];
    sales?: BusinessData | null;
    /** Module 6.3: the morning summary card; null when there is no news (deferred). */
    yesterday?: YesterdayGlance | null;
}
