import { type HeatmapChart, type SeriesChart } from '@/components/app/reports/types';
import { ChartCard, ChartLegend, SegmentedControl } from '@/components/shared/chart-card';
import { hourLabel, money, moneyAxis, number } from '@/components/shared/trading/format';
import { ChartTooltipBox } from '@/components/shared/trend-chart';
import { cn } from '@/lib/utils';
import { BarChart3, Grid3x3 } from 'lucide-react';
import { useState } from 'react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

/** A report's figure per period as bars, the compare window's periods as pale bars beside them. */
export function SeriesChartCard({ chart, compareLabel }: { chart: SeriesChart; compareLabel: string | null }) {
    const hasCompare = compareLabel !== null && chart.points.some((p) => p.compare !== null);
    const data = chart.points.map((p) => ({
        label: p.label,
        value: Number(p.value),
        compare: p.compare === null ? null : Number(p.compare),
        compareLabel: p.compareLabel,
    }));
    const axis = { stroke: 'var(--muted-foreground)', fontSize: 12, tickLine: false, axisLine: false } as const;

    return (
        <ChartCard
            title={chart.metric}
            subtitle={hasCompare ? `Against ${compareLabel?.toLowerCase()}` : undefined}
            icon={BarChart3}
            footer={
                hasCompare ? (
                    <ChartLegend
                        items={[
                            { label: 'This period', marker: 'bar' },
                            { label: compareLabel, tone: 'muted', marker: 'bar' },
                        ]}
                    />
                ) : undefined
            }
        >
            <div className="h-64 w-full">
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 0 }} barGap={2}>
                        <CartesianGrid vertical={false} stroke="var(--border)" />
                        <XAxis
                            dataKey="label"
                            {...axis}
                            dy={6}
                            minTickGap={16}
                            tickFormatter={(l: string) => l.replace(/^w\/c /, '').replace(/ \d{4}$/, '')}
                        />
                        <YAxis {...axis} width={56} tickFormatter={moneyAxis} />
                        <Tooltip
                            cursor={{ fill: 'var(--muted)', opacity: 0.6 }}
                            content={({ active, payload }) => {
                                const point = active && payload && payload.length > 0 ? (payload[0].payload as (typeof data)[number]) : null;

                                return point ? (
                                    <ChartTooltipBox
                                        title={point.label}
                                        rows={[
                                            { label: 'This period', value: money(point.value), tone: 'primary' },
                                            ...(hasCompare && point.compare !== null
                                                ? [{ label: point.compareLabel ?? 'Compare', value: money(point.compare), tone: 'muted' as const }]
                                                : []),
                                        ]}
                                    />
                                ) : null;
                            }}
                        />
                        {hasCompare && (
                            <Bar
                                dataKey="compare"
                                name={compareLabel ?? 'Compare'}
                                fill="color-mix(in oklab, var(--muted-foreground) 45%, transparent)"
                                radius={[4, 4, 0, 0]}
                                maxBarSize={24}
                                isAnimationActive={false}
                            />
                        )}
                        <Bar
                            dataKey="value"
                            name="This period"
                            fill="var(--primary)"
                            radius={[4, 4, 0, 0]}
                            maxBarSize={24}
                            isAnimationActive={false}
                        />
                    </BarChart>
                </ResponsiveContainer>
            </div>
        </ChartCard>
    );
}

type HeatMetric = 'averageNet' | 'transactions';

/** Weekday × hour: the darker the cell, the busier (average net sales per day, or total transactions). */
export function HeatmapCard({ chart }: { chart: HeatmapChart }) {
    const [metric, setMetric] = useState<HeatMetric>('averageNet');
    const value = (cell: HeatmapChart['rows'][number]['cells'][number]) =>
        metric === 'averageNet' ? Number(cell.averageNet ?? 0) : cell.transactions;
    const max = Math.max(0, ...chart.rows.flatMap((r) => r.cells.map(value)));

    return (
        <ChartCard
            title="Busy hours"
            subtitle={metric === 'averageNet' ? 'Average net sales per day of the week' : 'Transactions in the range'}
            icon={Grid3x3}
            controls={
                <SegmentedControl
                    label="Heatmap figure"
                    value={metric}
                    onChange={setMetric}
                    options={[
                        { value: 'averageNet', label: 'Sales' },
                        { value: 'transactions', label: 'Transactions' },
                    ]}
                />
            }
        >
            <div className="overflow-x-auto">
                <table className="w-full border-separate border-spacing-1 text-xs" aria-label="Sales by day of the week and hour">
                    <thead>
                        <tr>
                            <th className="w-12" />
                            {chart.hours.map((hour) => (
                                <th key={hour} scope="col" className="text-muted-foreground min-w-9 font-medium">
                                    {hourLabel(hour).slice(0, 2)}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {chart.rows.map((row) => (
                            <tr key={row.weekday}>
                                <th scope="row" className="text-muted-foreground pr-1 text-left font-medium">
                                    {row.label}
                                </th>
                                {row.cells.map((cell) => {
                                    const v = value(cell);
                                    const strength = max > 0 ? v / max : 0;
                                    const text = metric === 'averageNet' ? money(cell.averageNet) : number(cell.transactions);

                                    return (
                                        <td
                                            key={cell.hour}
                                            title={`${row.label} ${hourLabel(cell.hour)}: ${text}${metric === 'averageNet' ? ` a day (${row.days} days)` : ''}`}
                                            className={cn(
                                                'h-8 rounded-sm text-center tabular-nums',
                                                strength > 0.55 ? 'text-primary-foreground' : 'text-muted-foreground',
                                            )}
                                            style={{
                                                backgroundColor:
                                                    v > 0
                                                        ? `color-mix(in oklab, var(--primary) ${Math.round(12 + strength * 88)}%, transparent)`
                                                        : 'var(--muted)',
                                            }}
                                        >
                                            <span className="sr-only">{text}</span>
                                        </td>
                                    );
                                })}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="text-muted-foreground mt-3 flex items-center gap-2 text-xs">
                <span>Quiet</span>
                <span
                    className="h-2 w-24 rounded-full"
                    style={{ background: 'linear-gradient(to right, var(--muted), var(--primary))' }}
                    aria-hidden
                />
                <span>Busy</span>
                <span className="ml-auto">Hover a cell for its figure. Hours are shop time.</span>
            </div>
        </ChartCard>
    );
}
