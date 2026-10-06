import { EndsAtText, LicenceStatusBadge } from '@/components/app/shops/licence-bits';
import { type TillRow } from '@/components/app/shops/types';
import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { BranchHealthStrip } from '@/components/till-health/branch-health-strip';
import { ago, ProblemPills, shopDateTime, TillStateBadge } from '@/components/till-health/format';
import { type ShopHealth } from '@/components/till-health/types';
import { Badge } from '@/components/ui/badge';
import { KeyRound, Monitor, Star, TriangleAlert } from 'lucide-react';
import { type ReactNode } from 'react';

function Fact({ label, children, title }: { label: string; children: ReactNode; title?: string }) {
    return (
        <div className="min-w-0">
            <dt className="text-muted-foreground text-xs">{label}</dt>
            <dd className="mt-0.5 truncate text-[13px]" title={title}>
                {children}
            </dd>
        </div>
    );
}

/**
 * The shop's tills (module 4.7): each till's licence read only (status, end date, the key's last 4 characters) and
 * its health (last seen, last licence check, app version, last push / pull for the till that syncs).
 */
export function TillsCard({ tills, health, footer }: { tills: TillRow[]; health: ShopHealth | null; footer?: ReactNode }) {
    return (
        <SectionCard
            title="Tills"
            description="Each till has its own licence. Keys are shown by their last 4 characters only; we email the full key to the owner."
            flush
            footer={footer}
        >
            {tills.length === 0 ? (
                <EmptyState icon={Monitor} title="No tills yet" body="Tills appear here once Switch & Save has added them." size="sm" />
            ) : (
                <ul className="divide-y">
                    {tills.map((till) => (
                        <TillItem key={till.id} till={till} />
                    ))}
                </ul>
            )}
            <BranchHealthStrip health={health} />
        </SectionCard>
    );
}

function TillItem({ till }: { till: TillRow }) {
    const { licence, health } = till;
    const problems = health?.problems.filter((p) => p.value !== 'offline') ?? [];
    const syncs = health?.isSyncTill ?? false;

    return (
        <li className="px-4 py-4 sm:px-5">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex min-w-0 items-center gap-2.5">
                    <span className="bg-subtle text-muted-foreground flex size-8 shrink-0 items-center justify-center rounded-lg border">
                        <Monitor className="size-4" aria-hidden />
                    </span>
                    <div className="flex min-w-0 flex-wrap items-center gap-2">
                        <h3 className="truncate text-sm font-semibold">{till.name}</h3>
                        <span className="text-muted-foreground font-mono text-xs">{till.code}</span>
                        {till.isMainTill && (
                            <Badge variant="info">
                                <Star aria-hidden />
                                Main till
                            </Badge>
                        )}
                        {!till.isActive && <StatusBadge status="inactive" label="Switched off" />}
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-1.5 pl-10 sm:pl-0">
                    {licence ? (
                        <LicenceStatusBadge status={licence.status} label={licence.statusLabel} />
                    ) : (
                        <StatusBadge status="draft" label="No licence" />
                    )}
                    {health && <TillStateBadge state={health.state} label={health.stateLabel} />}
                </div>
            </div>

            {licence && !licence.canTrade && licence.status !== 'issued' && licence.reason && (
                <p className="text-danger-foreground mt-2 flex items-start gap-1.5 pl-10 text-[13px]">
                    <TriangleAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                    {licence.reason} Please call Switch & Save.
                </p>
            )}

            <dl className="mt-3 grid grid-cols-2 gap-x-6 gap-y-3 pl-10 sm:grid-cols-3 xl:grid-cols-4">
                <Fact label="Licence key">
                    <span className="inline-flex items-center gap-1.5 font-mono">
                        <KeyRound className="text-muted-foreground size-3.5 shrink-0" aria-hidden />
                        {licence ? licence.maskedKey : 'None'}
                    </span>
                </Fact>
                <Fact label={licence?.isTrial ? 'Trial ends' : 'Licence ends'} title={shopDateTime(licence?.endsAt)}>
                    {licence?.status === 'issued' ? (
                        <span className="text-muted-foreground">Not entered on the till yet</span>
                    ) : (
                        <EndsAtText iso={licence?.endsAt ?? null} prefix="" />
                    )}
                </Fact>
                <Fact label="Last licence check" title={shopDateTime(licence?.lastValidatedAt)}>
                    {ago(licence?.lastValidatedAt, 'Never')}
                </Fact>
                <Fact label="App version">{health?.appVersion ?? licence?.appVersion ?? <span className="text-muted-foreground">Unknown</span>}</Fact>
                <Fact label="Last seen" title={shopDateTime(health?.lastSeenAt)}>
                    {health?.state === 'notActivated' ? (
                        <span className="text-muted-foreground">Not activated</span>
                    ) : (
                        ago(health?.lastSeenAt, 'Never')
                    )}
                </Fact>
                <Fact label="Last push" title={shopDateTime(health?.lastPushAt)}>
                    {syncs ? ago(health?.lastPushAt, 'Never') : <span className="text-muted-foreground">Via the main till</span>}
                </Fact>
                <Fact label="Last pull" title={shopDateTime(health?.lastPullAt)}>
                    {syncs ? ago(health?.lastPullAt, 'Never') : <span className="text-muted-foreground">Via the main till</span>}
                </Fact>
                {problems.length > 0 && (
                    <Fact label="Needs a look">
                        <ProblemPills problems={problems} />
                    </Fact>
                )}
            </dl>
        </li>
    );
}
