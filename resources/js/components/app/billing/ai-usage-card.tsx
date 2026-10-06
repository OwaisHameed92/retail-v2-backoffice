import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { formatNumber } from '@/lib/country';
import { cn } from '@/lib/utils';

export interface AiUsage {
    used: number;
    limit: number;
    remaining: number;
    percent: number;
    resetsOn: string;
    calls: number;
    included: boolean;
    byFeature: { feature: string; label: string; tokens: number; calls: number }[];
    byPerson: { name: string; tokens: number; calls: number }[];
}

const n = (value: number) => formatNumber(value);

/** My subscription: this month's AI allowance (module 6.2). The same numbers the assistant is held to. */
export function AiUsageCard({ usage }: { usage: AiUsage }) {
    const tone = usage.percent >= 90 ? 'bg-destructive' : usage.percent >= 75 ? 'bg-warning' : 'bg-primary';

    return (
        <SectionCard
            title="AI assistant usage"
            description={`AI use this month, measured in tokens (pieces of text read and written). The allowance resets on ${usage.resetsOn}.`}
            actions={
                <StatusBadge
                    status={usage.included ? 'active' : 'inactive'}
                    tone={usage.included ? 'success' : 'neutral'}
                    label={usage.included ? 'Included in your plan' : 'Not in your plan'}
                />
            }
        >
            <div className="space-y-5 pb-5">
                <div className="space-y-2">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <p className="text-2xl font-semibold tracking-tight tabular-nums">{usage.percent}%</p>
                        <p className="text-muted-foreground text-sm tabular-nums">
                            {n(usage.used)} of {n(usage.limit)} tokens · {n(usage.calls)} AI {usage.calls === 1 ? 'call' : 'calls'}
                        </p>
                    </div>
                    <div
                        className="bg-muted h-2 overflow-hidden rounded-full"
                        role="progressbar"
                        aria-valuenow={usage.percent}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-label="AI allowance used"
                    >
                        <div className={cn('h-full rounded-full', tone)} style={{ width: `${Math.max(1, usage.percent)}%` }} />
                    </div>
                    {usage.percent >= 100 && (
                        <p className="text-destructive text-sm">This month's allowance is used. The assistant answers again from {usage.resetsOn}.</p>
                    )}
                </div>

                {usage.used > 0 && (
                    <div className="grid gap-5 sm:grid-cols-2">
                        {[
                            {
                                title: 'By feature',
                                rows: usage.byFeature.map((r) => ({ key: r.feature, label: r.label, tokens: r.tokens, calls: r.calls })),
                            },
                            {
                                title: 'By person',
                                rows: usage.byPerson.map((r, i) => ({ key: `${r.name}-${i}`, label: r.name, tokens: r.tokens, calls: r.calls })),
                            },
                        ].map((group) => (
                            <div key={group.title}>
                                <p className="text-muted-foreground mb-2 text-xs font-semibold tracking-wide uppercase">{group.title}</p>
                                <ul className="divide-border border-border divide-y rounded-lg border">
                                    {group.rows.map((row) => (
                                        <li key={row.key} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                            <span className="truncate">{row.label}</span>
                                            <span className="text-muted-foreground shrink-0 tabular-nums">{n(row.tokens)} tokens</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </SectionCard>
    );
}
