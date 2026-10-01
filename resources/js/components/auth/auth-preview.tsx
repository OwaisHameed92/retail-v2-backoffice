import { cn } from '@/lib/utils';
import { Activity, ArrowUpRight, CalendarClock, FileCheck2, KeyRound, PackageMinus, type LucideIcon } from 'lucide-react';
import { useId, type CSSProperties, type ReactNode } from 'react';

/*
 * The floating "product preview" on the sign-in brand panel: a few glassy cards built from real UI, layered with a
 * slight rotation. Purely illustrative (sample figures), so the whole thing is aria-hidden.
 */

const SALES = [18, 22, 20, 27, 25, 31, 29, 36, 34, 41, 39, 47, 52];
const TILLS = [96, 99, 101, 100, 106, 109, 108, 114, 117, 116, 121, 125, 128];

function Sparkline({ points, className }: { points: number[]; className?: string }) {
    const id = useId();
    const max = Math.max(...points);
    const min = Math.min(...points);
    const xy = points.map((value, i) => [(i / (points.length - 1)) * 200, 52 - ((value - min) / (max - min || 1)) * 44] as const);
    const line = xy.map(([x, y], i) => `${i === 0 ? 'M' : 'L'}${x.toFixed(1)} ${y.toFixed(1)}`).join(' ');

    return (
        <svg viewBox="0 0 200 56" preserveAspectRatio="none" className={cn('h-14 w-full', className)} fill="none">
            <defs>
                <linearGradient id={id} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="currentColor" stopOpacity="0.28" />
                    <stop offset="100%" stopColor="currentColor" stopOpacity="0" />
                </linearGradient>
            </defs>
            <path d={`${line} L200 56 L0 56 Z`} fill={`url(#${id})`} />
            <path d={line} stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" vectorEffect="non-scaling-stroke" />
        </svg>
    );
}

/** One layered card: the outer box positions and tilts it, the inner box runs the entrance animation. */
function Floating({ className, tilt, delay, children }: { className: string; tilt: string; delay: number; children: ReactNode }) {
    return (
        <div className={cn('absolute', className)} style={{ transform: `rotate(${tilt})` } as CSSProperties}>
            <div className="motion-safe:animate-auth-rise" style={{ animationDelay: `${delay}ms` }}>
                {children}
            </div>
        </div>
    );
}

const glass = 'bg-card/95 text-card-foreground shadow-float rounded-2xl border border-auth-ink/60 backdrop-blur-xl dark:border-auth-ink/10';

function Chip({ icon: Icon, tone, children }: { icon: LucideIcon; tone: 'warning' | 'success' | 'info'; children: ReactNode }) {
    const tones = {
        warning: 'bg-warning-soft text-warning-foreground',
        success: 'bg-success-soft text-success-foreground',
        info: 'bg-info-soft text-info-foreground',
    };

    return (
        <div className={cn(glass, 'flex items-center gap-2.5 rounded-xl py-2 pr-3.5 pl-2 text-[13px] font-medium whitespace-nowrap')}>
            <span className={cn('flex size-7 items-center justify-center rounded-lg', tones[tone])}>
                <Icon className="size-3.5" />
            </span>
            {children}
        </div>
    );
}

function StatusRow({ name, detail }: { name: string; detail: string }) {
    return (
        <li className="flex items-center justify-between gap-3 py-1.5 text-[13px]">
            <span className="flex min-w-0 items-center gap-2">
                <span className="relative flex size-2 shrink-0">
                    <span className="bg-success absolute inline-flex size-full rounded-full opacity-60 [animation-duration:2.4s] motion-safe:animate-ping" />
                    <span className="bg-success relative inline-flex size-2 rounded-full" />
                </span>
                <span className="truncate font-medium">{name}</span>
            </span>
            <span className="text-muted-foreground shrink-0 text-xs">{detail}</span>
        </li>
    );
}

function MetricCard({
    label,
    value,
    delta,
    sub,
    points,
    tone,
}: {
    label: string;
    value: string;
    delta: string;
    sub: string;
    points: number[];
    tone: string;
}) {
    return (
        <div className={cn(glass, 'p-5')}>
            <div className="flex items-center justify-between">
                <p className="text-muted-foreground text-xs font-medium">{label}</p>
                <span className="bg-success-soft text-success-foreground inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-semibold">
                    <ArrowUpRight className="size-3" />
                    {delta}
                </span>
            </div>
            <p className="mt-1.5 text-[28px] leading-9 font-semibold tracking-[-0.02em] tabular-nums">{value}</p>
            <p className="text-muted-foreground text-xs">{sub}</p>
            <Sparkline points={points} className={cn('mt-3', tone)} />
        </div>
    );
}

function ListCard({ title, badge, children }: { title: string; badge: string; children: ReactNode }) {
    return (
        <div className={cn(glass, 'p-4')}>
            <div className="mb-1.5 flex items-center justify-between gap-2">
                <p className="text-sm font-semibold">{title}</p>
                <span className="bg-success-soft text-success-foreground text-2xs inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold">
                    <Activity className="size-3" />
                    {badge}
                </span>
            </div>
            <ul className="divide-border divide-y">{children}</ul>
        </div>
    );
}

export function CustomerPreview() {
    return (
        <div className="relative h-[360px] w-full max-w-[470px] xl:h-[380px] xl:max-w-[520px]" aria-hidden>
            <Floating className="top-14 left-0 w-[70%]" tilt="-2deg" delay={250}>
                <MetricCard label="Today's sales" value="£4,812" delta="12%" sub="vs last Tuesday · 3 tills" points={SALES} tone="text-success" />
            </Floating>
            <Floating className="right-0 bottom-0 w-[58%]" tilt="2.5deg" delay={420}>
                <ListCard title="3 tills online" badge="Live">
                    <StatusRow name="Leeds · Till 1" detail="Synced now" />
                    <StatusRow name="Leeds · Till 2" detail="1 min ago" />
                    <StatusRow name="Bradford · Till 1" detail="Synced now" />
                </ListCard>
            </Floating>
            <Floating className="top-0 right-0 z-10" tilt="3deg" delay={560}>
                <Chip icon={PackageMinus} tone="warning">
                    Low stock: 6 items
                </Chip>
            </Floating>
            <Floating className="bottom-12 left-3 z-10" tilt="-3deg" delay={680}>
                <Chip icon={FileCheck2} tone="success">
                    VAT return ready
                </Chip>
            </Floating>
        </div>
    );
}

export function StaffPreview() {
    return (
        <div className="relative h-[360px] w-full max-w-[470px] xl:h-[380px] xl:max-w-[520px]" aria-hidden>
            <Floating className="top-14 left-0 w-[70%]" tilt="-2deg" delay={250}>
                <MetricCard label="Active tills" value="128" delta="6" sub="this week · 41 businesses" points={TILLS} tone="text-info" />
            </Floating>
            <Floating className="right-0 bottom-0 w-[58%]" tilt="2.5deg" delay={420}>
                <ListCard title="All systems normal" badge="Healthy">
                    <StatusRow name="Licence server" detail="99.99%" />
                    <StatusRow name="Till sync" detail="0 queued" />
                    <StatusRow name="Billing" detail="Up to date" />
                </ListCard>
            </Floating>
            <Floating className="top-0 right-0 z-10" tilt="3deg" delay={560}>
                <Chip icon={CalendarClock} tone="warning">
                    2 trials ending this week
                </Chip>
            </Floating>
            <Floating className="bottom-12 left-3 z-10" tilt="-3deg" delay={680}>
                <Chip icon={KeyRound} tone="info">
                    4 licences renewed today
                </Chip>
            </Floating>
        </div>
    );
}
