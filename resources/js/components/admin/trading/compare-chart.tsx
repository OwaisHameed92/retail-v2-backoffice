import { toneVar, type ChartTone } from '@/components/shared/trend-chart';
import { cn } from '@/lib/utils';
import { useId } from 'react';
import { Area, Bar, CartesianGrid, ComposedChart, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

export interface ComparePoint {
    /** Axis label ("24 Sept", "09:00"). */
    label: string;
    /** Tooltip heading, e.g. "Thu 24 Sept". */
    title: string;
    current: number | null;
    compare: number | null;
    /** Tooltip heading of the compare point ("Thu 17 Sept"). */
    compareTitle?: string | null;
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

function CompareTooltip({
    active,
    payload,
    format,
    currentName,
    compareName,
}: {
    active?: boolean;
    payload?: { payload?: ComparePoint }[];
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
                <span className="bg-primary size-2 rounded-full" aria-hidden />
                {currentName}: {point.current === null ? '—' : format(point.current)}
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

/**
 * A current series against its compare window: area (days) or bars (hours) in the brand tone, the compare as a
 * dashed muted line. Recessive grid and axes, hover tooltip with both values.
 */
export function CompareChart({ data, variant, tone = 'primary', format, axisFormat, currentName, compareName, className }: CompareChartProps) {
    const id = useId().replace(/:/g, '');
    const axis = { stroke: 'var(--muted-foreground)', fontSize: 12, tickLine: false, axisLine: false } as const;
    const showCompare = compareName !== undefined && data.some((p) => p.compare !== null);

    return (
        <div className={cn('h-64 w-full sm:h-72', className)} role="img" aria-label={`${currentName} chart`}>
            <ResponsiveContainer width="100%" height="100%">
                <ComposedChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                    <defs>
                        <linearGradient id={`cmp-${id}`} x1="0" x2="0" y1="0" y2="1">
                            <stop offset="0%" stopColor={toneVar[tone]} stopOpacity={0.22} />
                            <stop offset="100%" stopColor={toneVar[tone]} stopOpacity={0} />
                        </linearGradient>
                    </defs>
                    <CartesianGrid vertical={false} stroke="var(--border)" />
                    <XAxis dataKey="label" {...axis} dy={6} interval="preserveStartEnd" minTickGap={16} />
                    <YAxis {...axis} width={56} tickFormatter={axisFormat ?? format} />
                    <Tooltip
                        cursor={variant === 'bar' ? { fill: 'var(--muted)', opacity: 0.6 } : { stroke: 'var(--border-strong)', strokeDasharray: '3 3' }}
                        content={<CompareTooltip format={format} currentName={currentName} compareName={showCompare ? compareName : undefined} />}
                    />
                    {variant === 'bar' ? (
                        <Bar dataKey="current" fill={toneVar[tone]} radius={[4, 4, 0, 0]} maxBarSize={28} isAnimationActive={false} />
                    ) : (
                        <Area
                            type="monotone"
                            dataKey="current"
                            stroke={toneVar[tone]}
                            strokeWidth={2}
                            fill={`url(#cmp-${id})`}
                            dot={data.length <= 31 ? { r: 3, fill: toneVar[tone], stroke: 'var(--card)', strokeWidth: 2 } : false}
                            activeDot={{ r: 5, fill: toneVar[tone], stroke: 'var(--card)', strokeWidth: 2 }}
                            isAnimationActive={false}
                        />
                    )}
                    {showCompare && (
                        <Line
                            type="monotone"
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
