import { toneVar, type ChartTone } from '@/components/shared/trend-chart';
import { cn } from '@/lib/utils';
import { useId } from 'react';
import { Area, Bar, CartesianGrid, Cell, ComposedChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

export interface ComparePoint {
    /** Axis label ("24 Sept", "09:00"). */
    label: string;
    /** Tooltip heading, e.g. "Thu 24 Sept". */
    title: string;
    current: number | null;
    compare: number | null;
    /** Tooltip heading of the compare point ("Thu 17 Sept"). */
    compareTitle?: string | null;
    /** Today, or the hour now: still filling up, drawn as "so far" (never as a drop). */
    partial?: boolean;
}

interface CompareChartProps {
    data: ComparePoint[];
    variant: 'area' | 'bar';
    tone?: ChartTone;
    format: (value: number) => string;
    axisFormat?: (value: number) => string;
    currentName: string;
    compareName?: string;
    className?: string;
}

interface PlotPoint extends ComparePoint {
    /** Complete points (the area). */
    solid: number | null;
    /** The dashed "so far" segment: the last complete point and the partial one. */
    soFar: number | null;
}

function CompareTooltip({
    active,
    payload,
    format,
    currentName,
    compareName,
}: {
    active?: boolean;
    payload?: { payload?: PlotPoint }[];
    format: (value: number) => string;
    currentName: string;
    compareName?: string;
}) {
    const point = payload?.[0]?.payload;
    if (!active || !point) {
        return null;
    }

    return (
        <div className="bg-popover text-popover-foreground shadow-overlay grid gap-1 rounded-lg border px-3 py-2 text-xs">
            <p className="text-muted-foreground">{point.title}</p>
            <p className="flex items-center gap-2 font-semibold tabular-nums">
                <span className={cn('size-2 rounded-full', point.partial ? 'border-primary border-2' : 'bg-primary')} aria-hidden />
                {currentName}
                {point.partial ? ' so far' : ''}: {point.current === null ? '—' : format(point.current)}
            </p>
            {compareName && point.compare !== null && (
                <p className="text-muted-foreground flex items-center gap-2 tabular-nums">
                    <span className="bg-muted-foreground/60 size-2 rounded-full" aria-hidden />
                    {compareName}
                    {point.compareTitle ? ` (${point.compareTitle})` : ''}: {format(point.compare)}
                </p>
            )}
        </div>
    );
}

/* Recharts 2 does not look inside fragments: each series is its own conditional child. */

/** Splits the series so a partial point is drawn apart from the complete ones. */
function plot(data: ComparePoint[]): PlotPoint[] {
    const partialAt = data.findIndex((p) => p.partial && p.current !== null);

    return data.map((p, i) => ({
        ...p,
        solid: i === partialAt ? null : p.current,
        soFar: partialAt >= 0 && (i === partialAt || i === partialAt - 1) ? p.current : null,
    }));
}

/**
 * A current series against its compare window: area (days) or bars (hours) in the brand tone, the compare as a
 * dashed muted line. Curves are monotone (they never overshoot the data between points). A partial point (today,
 * or the current hour) is drawn as "so far": a dashed segment to a hollow dot, or a pale outlined bar, so a day that
 * is still trading never reads as a drop. Recessive grid and axes, hover tooltip with both values.
 */
export function CompareChart({ data, variant, tone = 'primary', format, axisFormat, currentName, compareName, className }: CompareChartProps) {
    const id = useId().replace(/:/g, '');
    const axis = { stroke: 'var(--muted-foreground)', fontSize: 12, tickLine: false, axisLine: false } as const;
    const showCompare = compareName !== undefined && data.some((p) => p.compare !== null);
    const points = plot(data);
    const colour = toneVar[tone];
    const dots = data.length <= 31;

    return (
        <div className={cn('h-64 w-full sm:h-72', className)} role="img" aria-label={`${currentName} chart`}>
            <ResponsiveContainer width="100%" height="100%">
                <ComposedChart data={points} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                    <defs>
                        <linearGradient id={`cmp-${id}`} x1="0" x2="0" y1="0" y2="1">
                            <stop offset="0%" stopColor={colour} stopOpacity={0.22} />
                            <stop offset="100%" stopColor={colour} stopOpacity={0} />
                        </linearGradient>
                    </defs>
                    <CartesianGrid vertical={false} stroke="var(--border)" />
                    <XAxis dataKey="label" {...axis} dy={6} interval="preserveStartEnd" minTickGap={16} />
                    <YAxis {...axis} width={56} tickFormatter={axisFormat ?? format} />
                    <Tooltip
                        cursor={
                            variant === 'bar' ? { fill: 'var(--muted)', opacity: 0.6 } : { stroke: 'var(--border-strong)', strokeDasharray: '3 3' }
                        }
                        content={<CompareTooltip format={format} currentName={currentName} compareName={showCompare ? compareName : undefined} />}
                    />
                    {variant === 'bar' && (
                        <Bar dataKey="current" fill={colour} radius={[4, 4, 0, 0]} maxBarSize={28} isAnimationActive={false}>
                            {points.map((p) => (
                                <Cell
                                    key={p.label}
                                    fill={colour}
                                    fillOpacity={p.partial ? 0.35 : 1}
                                    stroke={p.partial ? colour : undefined}
                                    strokeDasharray={p.partial ? '3 3' : undefined}
                                />
                            ))}
                        </Bar>
                    )}
                    {variant === 'area' && (
                        <Area
                            type="monotoneX"
                            dataKey="solid"
                            stroke={colour}
                            strokeWidth={2}
                            fill={`url(#cmp-${id})`}
                            connectNulls={false}
                            dot={dots ? { r: 3, fill: colour, stroke: 'var(--card)', strokeWidth: 2 } : false}
                            activeDot={{ r: 5, fill: colour, stroke: 'var(--card)', strokeWidth: 2 }}
                            isAnimationActive={false}
                        />
                    )}
                    {variant === 'area' && (
                        <Line
                            type="monotoneX"
                            dataKey="soFar"
                            stroke={colour}
                            strokeWidth={2}
                            strokeDasharray="5 4"
                            connectNulls={false}
                            dot={(props: { cx?: number; cy?: number; index?: number }) =>
                                props.index !== undefined && points[props.index]?.partial && props.cx !== undefined && props.cy !== undefined ? (
                                    <circle
                                        key={`so-far-${props.index}`}
                                        cx={props.cx}
                                        cy={props.cy}
                                        r={4}
                                        fill="var(--card)"
                                        stroke={colour}
                                        strokeWidth={2}
                                    />
                                ) : (
                                    <g key={`so-far-${props.index}`} />
                                )
                            }
                            activeDot={{ r: 5, fill: 'var(--card)', stroke: colour, strokeWidth: 2 }}
                            legendType="none"
                            isAnimationActive={false}
                        />
                    )}
                    {showCompare && (
                        <Line
                            type="monotoneX"
                            dataKey="compare"
                            stroke="var(--muted-foreground)"
                            strokeOpacity={0.7}
                            strokeWidth={1.5}
                            strokeDasharray="4 4"
                            dot={false}
                            activeDot={false}
                            isAnimationActive={false}
                        />
                    )}
                </ComposedChart>
            </ResponsiveContainer>
        </div>
    );
}
