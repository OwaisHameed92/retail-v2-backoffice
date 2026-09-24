import { cn } from '@/lib/utils';
import { useId } from 'react';

interface SparklineProps {
    /** Values in time order, oldest first. At least two points. */
    values: number[];
    /** Line colour token: chart-1 (blue, default), chart-2 (green) or destructive. */
    tone?: 'chart-1' | 'chart-2' | 'destructive' | 'muted';
    className?: string;
    /** Accessible summary, e.g. "Sales over the last 14 days, rising". Omit to hide from screen readers. */
    label?: string;
}

const strokeClass = {
    'chart-1': 'stroke-chart-1',
    'chart-2': 'stroke-chart-2',
    destructive: 'stroke-destructive',
    muted: 'stroke-muted-foreground',
} as const;

const fillClass = {
    'chart-1': 'text-chart-1',
    'chart-2': 'text-chart-2',
    destructive: 'text-destructive',
    muted: 'text-muted-foreground',
} as const;

/** Tiny trend line for StatCard's `chart` slot. Pure SVG, scales to its box (default 96×32). */
export function Sparkline({ values, tone = 'chart-1', className, label }: SparklineProps) {
    const id = useId();
    if (values.length < 2) {
        return null;
    }
    const width = 96;
    const height = 32;
    const min = Math.min(...values);
    const max = Math.max(...values);
    const span = max - min || 1;
    const points = values.map((value, index) => [(index / (values.length - 1)) * width, height - 2 - ((value - min) / span) * (height - 4)]);
    const line = points.map(([x, y], index) => `${index === 0 ? 'M' : 'L'}${x.toFixed(1)},${y.toFixed(1)}`).join(' ');
    const area = `${line} L${width},${height} L0,${height} Z`;

    return (
        <svg
            viewBox={`0 0 ${width} ${height}`}
            preserveAspectRatio="none"
            className={cn('h-8 w-24 overflow-visible', fillClass[tone], className)}
            role={label ? 'img' : undefined}
            aria-label={label}
            aria-hidden={label ? undefined : true}
        >
            <defs>
                <linearGradient id={`${id}-fill`} x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0%" stopColor="currentColor" stopOpacity="0.18" />
                    <stop offset="100%" stopColor="currentColor" stopOpacity="0" />
                </linearGradient>
            </defs>
            <path d={area} fill={`url(#${id}-fill)`} />
            <path
                d={line}
                fill="none"
                className={strokeClass[tone]}
                strokeWidth={1.75}
                strokeLinecap="round"
                strokeLinejoin="round"
                vectorEffect="non-scaling-stroke"
            />
        </svg>
    );
}

export default Sparkline;
