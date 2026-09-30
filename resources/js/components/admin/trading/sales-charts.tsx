import { CompareChart, type ComparePoint } from '@/components/admin/trading/compare-chart';
import { changeDelta, hourLabel, money, moneyAxis, moneyShort, number, shortDay, weekday } from '@/components/admin/trading/format';
import { type TradingData } from '@/components/admin/trading/types';
import { ChartCard, SegmentedControl, StatPill } from '@/components/shared/chart-card';
import { EmptyState } from '@/components/shared/empty-state';
import { BarChart3, Clock } from 'lucide-react';
import { useState } from 'react';

type Metric = 'net' | 'gross' | 'transactions';

const metrics: { value: Metric; label: string }[] = [
    { value: 'net', label: 'Net' },
    { value: 'gross', label: 'Inc VAT' },
    { value: 'transactions', label: 'Txns' },
];

const metricName: Record<Metric, string> = { net: 'Net sales', gross: 'Sales inc VAT', transactions: 'Transactions' };

const numeric = (value: string | number | null | undefined) => (value === null || value === undefined ? null : Number(value));

function formatFor(metric: Metric) {
    return metric === 'transactions' ? (v: number) => number(v) : (v: number) => money(v);
}

function NoSales({ body }: { body: string }) {
    return <EmptyState icon={BarChart3} title="No sales in this period" body={body} size="sm" />;
}

/**
 * "Sales by day" over the range (or "Sales by hour" for a single day), current against the compare window, with a
 * Net / Inc VAT / Transactions switch and the headline change beside it (DASHBOARD.md §2.3).
 */
export function SalesTrendCard({ data }: { data: TradingData }) {
    const [metric, setMetric] = useState<Metric>('net');
    const byDay = data.daily !== null;
    const compareName = data.range.compareFrom ? 'Compare' : undefined;
    const points: ComparePoint[] = byDay
        ? (data.daily ?? []).map((p) => ({
              label: shortDay(p.day),
              title: weekday(p.day),
              current: numeric(p[metric]),
              compare: numeric(metric === 'net' ? p.compareNet : metric === 'gross' ? p.compareGross : p.compareTransactions),
              compareTitle: p.compareDay ? weekday(p.compareDay) : null,
          }))
        : data.hourly
              .filter((p) => p.hour >= firstHour(data) && p.hour <= lastHour(data))
              .map((p) => ({
                  label: hourLabel(p.hour),
                  title: `${hourLabel(p.hour)}–${hourLabel((p.hour + 1) % 24)}`,
                  current: numeric(metric === 'transactions' ? p.transactions : metric === 'net' ? p.net : p.gross),
                  compare: metric === 'gross' ? null : numeric(metric === 'net' ? p.compareNet : p.compareTransactions),
              }));
    const headline = data.kpis.headline[metric === 'transactions' ? 'transactions' : metric];
    const delta = changeDelta(headline.change, data.range.compareLabel);
    const empty = data.kpis.totals.transactions === 0 && data.kpis.totals.refundCount === 0;

    return (
        <ChartCard
            title={byDay ? 'Sales by day' : 'Sales by hour'}
            subtitle={byDay ? `${metricName[metric]}, ${data.range.days} trading days` : `${metricName[metric]}, Europe/London time`}
            icon={BarChart3}
            controls={<SegmentedControl label="Figure" options={metrics} value={metric} onChange={setMetric} />}
            stat={
                !empty && delta ? (
                    <StatPill
                        value={delta.value}
                        label={delta.label}
                        direction={delta.direction === 'down' ? 'down' : 'up'}
                        good={delta.direction !== 'down'}
                    />
                ) : undefined
            }
            footer={
                compareName && !empty ? (
                    <span className="inline-flex items-center gap-2">
                        <span className="border-muted-foreground inline-block w-5 border-t-2 border-dashed" aria-hidden />
                        Dashed line: {data.range.compareLabel.replace(/^vs /, '')}
                        {data.range.isToday ? ' (whole day)' : ''}
                    </span>
                ) : undefined
            }
        >
            {empty ? (
                <NoSales body="Figures appear here as soon as the tills push sales for these days." />
            ) : (
                <CompareChart
                    data={points}
                    variant={byDay ? 'area' : 'bar'}
                    format={formatFor(metric)}
                    axisFormat={metric === 'transactions' ? (v) => number(v) : moneyAxis}
                    currentName={metricName[metric]}
                    compareName={compareName}
                />
            )}
        </ChartCard>
    );
}

/** First and last hour with trade in either window (so a shop open 07–22 is not squeezed into 24 bars). */
function firstHour(data: TradingData): number {
    const hours = data.hourly.filter((p) => Number(p.transactions ?? 0) > 0 || Number(p.compareTransactions ?? 0) > 0).map((p) => p.hour);

    return hours.length ? Math.min(...hours) : 0;
}

function lastHour(data: TradingData): number {
    const hours = data.hourly.filter((p) => Number(p.transactions ?? 0) > 0 || Number(p.compareTransactions ?? 0) > 0).map((p) => p.hour);

    return hours.length ? Math.max(...hours) : 23;
}

/**
 * The hourly pattern over a multi-day range: average net sales per trading day in each local hour, with the busiest
 * hour called out.
 */
export function HourlyPatternCard({ data }: { data: TradingData }) {
    const days = Math.max(1, data.range.days);
    const points: ComparePoint[] = data.hourly
        .filter((p) => p.hour >= firstHour(data) && p.hour <= lastHour(data))
        .map((p) => ({
            label: hourLabel(p.hour),
            title: `${hourLabel(p.hour)}–${hourLabel((p.hour + 1) % 24)}, average day`,
            current: p.net === null ? null : Number(p.net) / days,
            compare: p.compareNet === null ? null : Number(p.compareNet) / days,
        }));
    const busiest = [...data.hourly].sort((a, b) => Number(b.net ?? 0) - Number(a.net ?? 0))[0];
    const empty = data.kpis.totals.transactions === 0;

    return (
        <ChartCard
            title="Hourly pattern"
            subtitle="Average net sales per day, by hour"
            icon={Clock}
            tone="info"
            footer={
                !empty && busiest ? (
                    <span>
                        Busiest hour {hourLabel(busiest.hour)}–{hourLabel((busiest.hour + 1) % 24)}: {moneyShort(Number(busiest.net ?? 0) / days)} a day,{' '}
                        {number(Math.round(Number(busiest.transactions ?? 0) / days))} transactions
                    </span>
                ) : undefined
            }
        >
            {empty ? (
                <NoSales body="The busiest hours of the chosen shops show here." />
            ) : (
                <CompareChart
                    data={points}
                    variant="bar"
                    tone="info"
                    format={(v) => money(v)}
                    axisFormat={moneyAxis}
                    currentName="Net sales"
                    compareName={data.range.compareFrom ? 'Compare' : undefined}
                />
            )}
        </ChartCard>
    );
}
