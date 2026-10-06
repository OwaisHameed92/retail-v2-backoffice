import { type DashboardRange, type RevenueChartData } from '@/components/admin/dashboard/types';
import { ChartCard, SegmentedControl, StatPill, type SegmentOption } from '@/components/shared/chart-card';
import { EmptyState } from '@/components/shared/empty-state';
import { MoneyIcon } from '@/components/shared/money-icon';
import { TrendChart } from '@/components/shared/trend-chart';
import { formatMoneyTrim } from '@/lib/country';
import { cn } from '@/lib/utils';
import { BarChart3, Lock } from 'lucide-react';

export const dashboardRanges: SegmentOption<DashboardRange>[] = [
    { value: '12w', label: '12W' },
    { value: '6m', label: '6M' },
    { value: '1y', label: '1Y' },
];

export const dashboardRangeLabel: Record<DashboardRange, string> = { '12w': 'Last 12 weeks', '6m': 'Last 6 months', '1y': 'Last 12 months' };

function Stat({ chart }: { chart: RevenueChartData }) {
    const { change } = chart;

    return (
        <div className="flex flex-row flex-wrap items-start gap-3 lg:flex-col">
            <div>
                <p className="text-muted-foreground text-[11px]">Paid in period</p>
                <p className="text-foreground text-lg font-bold tabular-nums">{chart.total}</p>
            </div>
            {change && change.direction !== 'flat' ? (
                <StatPill value={change.value} label={change.label} direction={change.direction} good={change.direction === 'up'} />
            ) : (
                <p className="text-muted-foreground text-[11px]">{change ? `No change ${change.label}` : 'Nothing to compare with yet'}</p>
            )}
        </div>
    );
}

/** Revenue chart card: paid invoices per week or month, range picked server-side (`?range=`). */
export function RevenueCard({
    chart,
    range,
    loading,
    onRangeChange,
}: {
    /** Null when the admin has no billing access. */
    chart: RevenueChartData | null;
    range: DashboardRange;
    loading: boolean;
    onRangeChange: (range: DashboardRange) => void;
}) {
    const empty = chart !== null && chart.points.every((point) => point.value === 0);

    return (
        <ChartCard
            title="Revenue"
            subtitle={chart ? `${dashboardRangeLabel[range]} · paid invoices` : 'Paid invoices'}
            icon={BarChart3}
            controls={chart && <SegmentedControl label="Revenue range" options={dashboardRanges} value={range} onChange={onRangeChange} />}
            stat={chart && !empty && <Stat chart={chart} />}
        >
            <div className={cn('transition-opacity', loading && 'opacity-50')} aria-busy={loading}>
                {chart === null ? (
                    <EmptyState
                        icon={Lock}
                        title="Revenue is for billing staff"
                        body="Ask an owner for billing access to see paid invoices here."
                        size="sm"
                        bordered
                    />
                ) : empty ? (
                    <EmptyState
                        icon={MoneyIcon}
                        title="No paid invoices in this period"
                        body="Invoices chart here by the date they were paid in full."
                        size="sm"
                        bordered
                    />
                ) : (
                    <TrendChart
                        data={chart.points}
                        variant={range === '12w' ? 'line' : 'bar'}
                        format={(value) => formatMoneyTrim(value)}
                        seriesName="Paid"
                    />
                )}
            </div>
        </ChartCard>
    );
}
