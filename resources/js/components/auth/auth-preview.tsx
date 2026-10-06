import { formatMoneyWhole, taxText } from '@/lib/country';
import { cn } from '@/lib/utils';
import { ArrowUpRight } from 'lucide-react';
import { useId, type ReactNode } from 'react';
import { localPlaces, ukOnly } from '@/lib/country-text';

/*
 * The "product preview" on the sign-in brand panel: dark glass cards built from real UI, set straight in a tidy
 * staggered stack. Purely illustrative (sample figures), so the whole thing is aria-hidden.
 */

const SALES = [18, 22, 20, 27, 25, 31, 29, 36, 34, 41, 39, 47, 52];
const TILLS = [96, 99, 101, 100, 106, 109, 108, 114, 117, 116, 121, 125, 128];

type Tone = 'green' | 'blue' | 'amber' | 'cyan';

const dots: Record<Tone, string> = {
    green: 'bg-[#2ee06a] shadow-[0_0_10px_2px_rgb(46_224_106/0.55)]',
    blue: 'bg-[#4d8dff] shadow-[0_0_10px_2px_rgb(77_141_255/0.55)]',
    amber: 'bg-[#ffb547] shadow-[0_0_10px_2px_rgb(255_181_71/0.5)]',
    cyan: 'bg-[#3fd6e0] shadow-[0_0_10px_2px_rgb(63_214_224/0.5)]',
};

const strokes: Record<'green' | 'blue', string> = { green: 'text-[#2ee06a]', blue: 'text-[#5b95ff]' };

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
                    <stop offset="0%" stopColor="currentColor" stopOpacity="0.32" />
                    <stop offset="100%" stopColor="currentColor" stopOpacity="0" />
                </linearGradient>
            </defs>
            <path d={`${line} L200 56 L0 56 Z`} fill={`url(#${id})`} />
            <path d={line} stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" vectorEffect="non-scaling-stroke" />
        </svg>
    );
}

/** One card in the stack: the outer box positions it, the inner box runs the entrance animation. */
function Placed({ className, delay, children }: { className: string; delay: number; children: ReactNode }) {
    return (
        <div className={cn('absolute', className)}>
            <div className="motion-safe:animate-auth-rise" style={{ animationDelay: `${delay}ms` }}>
                {children}
            </div>
        </div>
    );
}

function Dot({ tone, pulse = false }: { tone: Tone; pulse?: boolean }) {
    return (
        <span className="relative flex size-2 shrink-0">
            {pulse && (
                <span
                    className={cn(
                        'absolute inline-flex size-full rounded-full opacity-60 [animation-duration:2.4s] motion-safe:animate-ping',
                        dots[tone],
                    )}
                />
            )}
            <span className={cn('relative inline-flex size-2 rounded-full', dots[tone])} />
        </span>
    );
}

function Chip({ tone, children }: { tone: Tone; children: ReactNode }) {
    return (
        <div className="auth-glass flex items-center gap-2.5 rounded-full py-2 pr-4 pl-3 text-[13px] font-medium whitespace-nowrap text-white">
            <Dot tone={tone} />
            {children}
        </div>
    );
}

function StatusRow({ name, detail }: { name: string; detail: string }) {
    return (
        <li className="flex items-center justify-between gap-3 py-2 text-[13px]">
            <span className="flex min-w-0 items-center gap-2.5">
                <Dot tone="green" pulse />
                <span className="truncate font-medium text-white">{name}</span>
            </span>
            <span className="shrink-0 text-xs text-white/65 tabular-nums">{detail}</span>
        </li>
    );
}

function MetricCard(props: { label: string; value: string; delta: string; sub: string; points: number[]; tone: 'green' | 'blue' }) {
    return (
        <div className="auth-glass rounded-2xl p-5 text-white">
            <div className="flex items-center justify-between">
                <p className="text-xs font-medium tracking-[0.02em] text-white/70">{props.label}</p>
                <span className="inline-flex items-center gap-0.5 rounded-full bg-[#2ee06a]/15 px-2 py-0.5 text-xs font-semibold text-[#7ff0a4] ring-1 ring-[#2ee06a]/25">
                    <ArrowUpRight className="size-3" />
                    {props.delta}
                </span>
            </div>
            <p className="mt-2 text-[30px] leading-9 font-bold tracking-[-0.03em] tabular-nums">{props.value}</p>
            <p className="text-xs text-white/65">{props.sub}</p>
            <Sparkline points={props.points} className={cn('mt-3', strokes[props.tone])} />
        </div>
    );
}

function ListCard({ title, badge, children }: { title: string; badge: string; children: ReactNode }) {
    return (
        <div className="auth-glass rounded-2xl p-4 text-white">
            <div className="mb-1 flex items-center justify-between gap-2">
                <p className="text-sm font-semibold">{title}</p>
                <span className="text-2xs inline-flex items-center gap-1.5 rounded-full bg-white/8 px-2 py-0.5 font-semibold text-white/85 ring-1 ring-white/12">
                    <Dot tone="green" />
                    {badge}
                </span>
            </div>
            <ul className="divide-y divide-white/8">{children}</ul>
        </div>
    );
}

interface PreviewProps {
    metric: Parameters<typeof MetricCard>[0];
    list: { title: string; badge: string; rows: [string, string][] };
    chips: [{ tone: Tone; text: string }, { tone: Tone; text: string }];
}

/**
 * Two columns, staggered and straight, never overlapping (glass over glass reads muddy): the metric card high on the
 * left with a chip under it, the status list lower on the right with a chip above it.
 */
function Preview({ metric, list, chips }: PreviewProps) {
    return (
        <div className="relative h-[272px] w-[480px]" aria-hidden>
            <Placed className="top-0 left-0 w-[50%]" delay={240}>
                <MetricCard {...metric} />
            </Placed>
            <Placed className="top-1 right-0" delay={320}>
                <Chip tone={chips[0].tone}>{chips[0].text}</Chip>
            </Placed>
            <Placed className="top-16 right-0 w-[47%]" delay={420}>
                <ListCard title={list.title} badge={list.badge}>
                    {list.rows.map(([name, detail]) => (
                        <StatusRow key={name} name={name} detail={detail} />
                    ))}
                </ListCard>
            </Placed>
            <Placed className="bottom-0 left-0" delay={540}>
                <Chip tone={chips[1].tone}>{chips[1].text}</Chip>
            </Placed>
        </div>
    );
}

export function CustomerPreview() {
    return (
        <Preview
            metric={{
                label: "Today's sales",
                value: formatMoneyWhole(4812),
                delta: '12%',
                sub: 'vs last Tuesday · 3 tills',
                points: SALES,
                tone: 'green',
            }}
            list={{
                title: '3 tills online',
                badge: 'Live',
                rows: [
                    [localPlaces('Leeds · Till 1'), 'Synced now'],
                    [localPlaces('Leeds · Till 2'), '1 min ago'],
                    [localPlaces('Bradford · Till 1'), 'Synced now'],
                ],
            }}
            chips={[
                { tone: 'amber', text: 'Low stock: 6 items' },
                { tone: 'green', text: ukOnly('VAT return ready', taxText('VAT report ready')) },
            ]}
        />
    );
}

export function StaffPreview() {
    return (
        <Preview
            metric={{ label: 'Active tills', value: '128', delta: '6', sub: 'this week · 41 businesses', points: TILLS, tone: 'blue' }}
            list={{
                title: 'All systems normal',
                badge: 'Healthy',
                rows: [
                    ['Licence server', '99.99%'],
                    ['Till sync', '0 queued'],
                    ['Billing', 'Up to date'],
                ],
            }}
            chips={[
                { tone: 'amber', text: '2 trials ending soon' },
                { tone: 'cyan', text: '4 licences renewed' },
            ]}
        />
    );
}
