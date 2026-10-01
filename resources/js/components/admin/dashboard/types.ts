import { type TillHealthSummary } from '@/components/till-health/types';
import { type AttentionItem } from '@/components/shared/attention-list';
import { type HealthState } from '@/components/shared/health-list';
import { type StatDelta } from '@/components/shared/stat-card';
import { type TrendPoint } from '@/components/shared/trend-chart';

/** Matches `App\Domain\Admin\Enums\DashboardRange`. */
export type DashboardRange = '12w' | '6m' | '1y';

/** A KPI or tile figure. `locked`: hidden from this admin (no billing access); value is then null. */
export interface DashboardFigure {
    locked: boolean;
    value: string | null;
    delta: StatDelta | null;
    series?: number[];
    footer?: string | null;
}

export interface RecentTenant {
    id: string;
    name: string;
    plan: string | null;
    tills: number;
    /** "£60.00"; null when the admin has no billing access. */
    mrr: string | null;
    lastActivityAt: string | null;
    status: string;
    statusLabel: string;
    href: string;
}

export interface DashboardHealth {
    key: string;
    name: string;
    state: HealthState;
    detail: string;
}

/** `RecentActivity::collect()`: a tenant created, an invoice paid or a lead created. */
export interface DashboardActivity {
    id: string;
    kind: 'tenant' | 'invoice' | 'lead';
    title: string;
    detail: string;
    /** UTC ISO timestamp. */
    at: string;
    href: string;
}

/** `RecentActivity::statusCounts()`: tenants (not deleted) per status. */
export interface DashboardStatuses {
    total: number;
    active: number;
    trial: number;
    overdue: number;
    suspended: number;
    cancelled: number;
}

/** `AdminDashboardData::forViewer()`: the "dashboard" page prop. */
export interface AdminDashboardProps {
    generatedAt: string;
    access: { billing: boolean; leads: boolean };
    kpis: Record<'revenue' | 'activeTills' | 'trials' | 'overdue', DashboardFigure>;
    overview: Record<'tenants' | 'revenue', DashboardFigure>;
    attention: { items: AttentionItem[]; total: number };
    recentTenants: RecentTenant[];
    health: DashboardHealth[];
    /** Module 2.7: Till health counts. */
    tills: TillHealthSummary;
    /** Module 7.1: newest 5 the admin may see. */
    activity: DashboardActivity[];
    statuses: DashboardStatuses;
}

/** `RevenueChart::for()`: the "revenue" page prop (null without billing access). */
export interface RevenueChartData {
    range: DashboardRange;
    label: string;
    points: TrendPoint[];
    total: string;
    change: StatDelta | null;
}
