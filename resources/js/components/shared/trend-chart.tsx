import { cn } from '@/lib/utils';
import { useId } from 'react';
import { Area, AreaChart, Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

/** Colour tones shared by KPI cards, sparklines and charts. Each maps to a token in app.css. */
export type ChartTone = 'primary' | 'success' | 'info' | 'violet' | 'warning' | 'danger';

export const toneVar: Record<ChartTone, string> = {
    primary: 'var(--primary)',
    success: 'var(--success)',
    info: 'var(--info)',
    violet: 'var(--violet)',
    warning: 'var(--warning)',
    danger: 'var(--danger)',
};

/** Soft icon circle per tone (KPI cards, overview tiles, chart titles). */
export const toneCircle: Record<ChartTone | 'neutral', string> = {
    primary: 'bg-primary-soft text-primary',
    success: 'bg-success-soft text-success-foreground',
    info: 'bg-info-soft text-info-foreground',
    violet: 'bg-violet-soft text-violet-foreground',
    warning: 'bg-warning-soft text-warning-foreground',
    danger: 'bg-danger-soft text-danger-foreground',
    neutral: 'bg-muted text-muted-foreground',
};

interface AreaSparklineProps {
    /** Values in time order, oldest first. Fewer than two points renders nothing. */
    values: number[];
    tone?: ChartTone;
    className?: string;
    /** Accessible summary, e.g. "Revenue over the last 12 weeks, rising". Omit to hide from screen readers. */
    label?: string;
}

/** Recharts sparkline with a soft area fill, for KpiCard. Fills its box (default full width × 56px). */
export function AreaSparkline({ values, tone = 'primary', className, label }: AreaSparklineProps) {
    const id = useId().replace(/:/g, '');
    if (values.length < 2) {
        return null;
    }
    const data = values.map((value, index) => ({ index, value }));

    return (
        <div className={cn('h-14 w-full', className)} role={label ? 'img' : undefined} aria-label={label} aria-hidden={label ? undefined : true}>
            <ResponsiveContainer width="100%" height="100%">
                <AreaChart data={data} margin={{ top: 4, right: 2, bottom: 0, left: 2 }}>
                    <defs>
                        <linearGradient id={`spark-${id}`} x1="0" x2="0" y1="0" y2="1">
                            <stop offset="0%" stopColor={toneVar[tone]} stopOpacity={0.22} />
                            <stop offset="100%" stopColor={toneVar[tone]} stopOpacity={0} />
                        </linearGradient>
                    </defs>
                    <YAxis hide domain={['dataMin', 'dataMax']} />
                    <Area
                        type="monotone"
                        dataKey="value"
                        stroke={toneVar[tone]}
                        strokeWidth={2}
                        fill={`url(#spark-${id})`}
                        isAnimationActive={false}
                        dot={false}
                    />
                </AreaChart>
            </ResponsiveContainer>
        </div>
    );
}

export interface TrendPoint {
    /** X-axis label, e.g. "W1" or "Mar". */
    label: string;
    value: number;
}

interface TrendChartProps {
    data: TrendPoint[];
    /** `line` = line with point markers and a soft area; `bar` = columns. */
    variant?: 'line' | 'bar';
    tone?: ChartTone;
    /** Formats axis ticks and the tooltip, e.g. (v) => `£${v}`. */
    format?: (value: number) => string;
    /** Name shown in the tooltip, e.g. "Revenue". */
    seriesName?: string;
    className?: string;
}

function TrendTooltip({
    active,
    payload,
    label,
    format,
    seriesName,
}: {
    active?: boolean;
    payload?: { value?: number | string }[];
    label?: string;
    format: (value: number) => string;
    seriesName: string;
}) {
    if (!active || !payload?.length) {
        return null;
    }

    return (
        <div className="bg-popover text-popover-foreground shadow-overlay rounded-lg border px-3 py-2 text-xs">
            <p className="text-muted-foreground mb-0.5">{label}</p>
            <p className="font-semibold tabular-nums">
                {seriesName}: {format(Number(payload[0].value ?? 0))}
            </p>
        </div>
    );
}

/** Single-series trend chart for ChartCard: recessive grid and axes, one tone, hover tooltip. */
export function TrendChart({ data, variant = 'line', tone = 'primary', format = String, seriesName = 'Value', className }: TrendChartProps) {
    const id = useId().replace(/:/g, '');
    const axis = { stroke: 'var(--muted-foreground)', fontSize: 12, tickLine: false, axisLine: false } as const;
    const tooltip = (
        <Tooltip
            cursor={variant === 'bar' ? { fill: 'var(--muted)', opacity: 0.6 } : { stroke: 'var(--border-strong)', strokeDasharray: '3 3' }}
            content={<TrendTooltip format={format} seriesName={seriesName} />}
        />
    );

    return (
        <div className={cn('h-64 w-full', className)}>
            <ResponsiveContainer width="100%" height="100%">
                {variant === 'bar' ? (
                    <BarChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                        <CartesianGrid vertical={false} stroke="var(--border)" />
                        <XAxis dataKey="label" {...axis} dy={6} />
                        <YAxis {...axis} width={48} tickFormatter={format} />
                        {tooltip}
                        <Bar dataKey="value" fill={toneVar[tone]} radius={[4, 4, 0, 0]} maxBarSize={28} isAnimationActive={false} />
                    </BarChart>
                ) : (
                    <AreaChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                        <defs>
                            <linearGradient id={`trend-${id}`} x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0%" stopColor={toneVar[tone]} stopOpacity={0.2} />
                                <stop offset="100%" stopColor={toneVar[tone]} stopOpacity={0} />
                            </linearGradient>
                        </defs>
                        <CartesianGrid vertical={false} stroke="var(--border)" />
                        <XAxis dataKey="label" {...axis} dy={6} />
                        <YAxis {...axis} width={48} tickFormatter={format} />
                        {tooltip}
                        <Area
                            type="linear"
                            dataKey="value"
                            name={seriesName}
                            stroke={toneVar[tone]}
                            strokeWidth={2}
                            fill={`url(#trend-${id})`}
                            dot={{ r: 4, fill: toneVar[tone], stroke: 'var(--card)', strokeWidth: 2 }}
                            activeDot={{ r: 5, fill: toneVar[tone], stroke: 'var(--card)', strokeWidth: 2 }}
                            isAnimationActive={false}
                        />
                    </AreaChart>
                )}
            </ResponsiveContainer>
        </div>
    );
}
