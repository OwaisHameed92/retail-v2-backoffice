import { type GroupRow, type Option, type PeriodFilters, type ProductRow, type SalesDashboardData, type TradingCompare, type TradingPeriod } from '@/components/shared/trading/types';

export type { DayPoint, HourPoint, Option, SalesTotals, TenderRow, TradingCompare, TradingFigure, TradingPeriod, VatRow } from '@/components/shared/trading/types';

/** `TradingFilters::toArray()`. */
export interface TradingFilters extends PeriodFilters {
    company: string | null;
    branch: string | null;
}

/** `TradingContext::for()`: the drill-down. */
export interface TradingContext {
    company: { id: string; name: string } | null;
    branch: { id: string; name: string } | null;
    branches: { id: string; name: string; code: string; active: boolean }[];
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
export type TillRow = GroupRow;

/** `TradingDashboard::compute()`. */
export interface TradingData extends SalesDashboardData {
    level: 'all' | 'business' | 'shop';
    activity: {
        companies: number;
        branches: number;
        registers: number;
        rebuiltAt: string | null;
        lastPushAt: string | null;
        pendingDays: number;
    };
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
