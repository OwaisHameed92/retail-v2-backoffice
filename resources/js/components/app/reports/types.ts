import { type BusinessContext, type BusinessFilters } from '@/components/app/dashboard/types';
import { type FreshnessInfo, type Option, type TradingCompare, type TradingPeriod } from '@/components/shared/trading/types';

/** Matches `App\Domain\Reporting\Reports\ReportKind`. */
export type ReportKey = 'sales' | 'products' | 'refunds' | 'discounts' | 'vat' | 'tenders' | 'staff' | 'hourly' | 'stock' | 'shifts';

export type ReportGrouping = 'day' | 'week' | 'month';

/** `ReportKind::options()`. */
export interface ReportOption {
    value: ReportKey;
    label: string;
    description: string;
    section: string;
}

/** `ReportController::meta()`. */
export interface ReportMeta extends ReportOption {
    usesDates: boolean;
    compares: boolean;
    groups: boolean;
    views: { value: string; label: string }[];
}

/** `ReportController::filters()`: the dashboard's filters plus the report's own. */
export interface ReportFilters extends BusinessFilters {
    group: ReportGrouping;
    view: string;
    page: number;
    report: ReportKey | null;
}

export type ColumnType = 'text' | 'money' | 'signedMoney' | 'qty' | 'count' | 'percent' | 'date' | 'datetime' | 'status' | 'flag';

export type CellValue = string | number | boolean | null;

/** `ReportTable::toArray()`. */
export interface ReportTableData {
    key: string;
    title: string;
    columns: { key: string; label: string; type: ColumnType }[];
    rows: Record<string, CellValue>[];
    totals: Record<string, CellValue> | null;
    description: string | null;
    empty: string;
    pagination: { page: number; lastPage: number; total: number; perPage: number } | null;
}

/** `Figures::of()`. */
export interface ReportFigure {
    key: string;
    label: string;
    value: string | number | null;
    type: ColumnType;
    previous: string | number | null;
    change: string | null;
    goodWhen: 'up' | 'down';
    hint: string | null;
}

export interface SeriesChart {
    type: 'series';
    metric: string;
    points: { label: string; value: string; compare: string | null; compareLabel: string | null }[];
}

export interface HeatmapChart {
    type: 'heatmap';
    hours: number[];
    rows: { weekday: number; label: string; days: number; cells: { hour: number; net: string; transactions: number; averageNet: string | null }[] }[];
}

/** `ReportResult::toArray()`. */
export interface ReportResultData {
    summary: ReportFigure[];
    tables: ReportTableData[];
    chart: SeriesChart | HeatmapChart | null;
    notes: string[];
    available: boolean;
}

export interface ReportShowProps {
    report: ReportMeta;
    reports: ReportOption[];
    filters: ReportFilters;
    context: BusinessContext;
    periods: Option<TradingPeriod>[];
    compares: Option<TradingCompare>[];
    groupings: Option<ReportGrouping>[];
    result: ReportResultData;
    freshness: (FreshnessInfo & { hasData: boolean }) | null;
}

export interface ReportIndexProps {
    reports: ReportOption[];
    filters: ReportFilters;
    context: BusinessContext;
}

export interface ReportPrintProps {
    report: ReportMeta;
    filters: ReportFilters;
    heading: Record<string, string>;
    result: ReportResultData;
}
