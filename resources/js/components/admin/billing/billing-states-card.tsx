import { type BillingState, type BillingStateGroup } from '@/components/shared/billing-status-card';
import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill, type StatusTone } from '@/components/shared/status-badge';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import { ChevronRight, Users } from 'lucide-react';
import { byHand, manualCollection } from '@/lib/billing-collection';

type FilterGroup = Exclude<BillingStateGroup, 'cancelled'>;

export interface BillingStatesData {
    counts: Record<FilterGroup, number>;
    total: number;
    group: FilterGroup | null;
    rows: {
        id: string;
        name: string;
        demo: boolean;
        planType: string | null;
        state: BillingState;
        group: BillingStateGroup;
        tone: StatusTone;
        headline: string;
        nextDate: string | null;
    }[];
    truncated: boolean;
}

const FILTERS: { value: FilterGroup; label: string; tone: StatusTone }[] = [
    { value: 'paid', label: 'Paid', tone: 'success' },
    { value: 'trial', label: 'On trial', tone: 'info' },
    { value: 'waitingForDirectDebit', label: 'Waiting for Direct Debit', tone: 'warning' },
    { value: 'setupDue', label: 'Setup fee due', tone: 'info' },
    { value: 'overdue', label: 'Overdue', tone: 'danger' },
    { value: 'suspended', label: 'Suspended', tone: 'danger' },
];

function filter(group: FilterGroup | null) {
    router.get(route('admin.billing.index'), group ? { state: group } : {}, { preserveScroll: true, preserveState: true, only: ['businesses'] });
}

function Chip({ active, label, count, tone, onClick }: { active: boolean; label: string; count: number; tone?: StatusTone; onClick: () => void }) {
    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className={cn(
                'inline-flex h-8 items-center gap-2 rounded-full border px-3 text-sm font-medium transition-colors',
                active ? 'border-primary bg-primary/10 text-foreground' : 'bg-card text-muted-foreground hover:bg-muted/70 hover:text-foreground',
            )}
        >
            {label}
            <StatusPill tone={count > 0 && tone ? tone : 'neutral'} className="h-5 px-1.5">
                {count}
            </StatusPill>
        </button>
    );
}

/** Admin billing overview: every business by billing state, with a filter per state and a badge per business. */
export function BillingStatesCard({ data }: { data: BillingStatesData }) {
    return (
        <SectionCard
            title="Businesses by billing state"
            description={byHand(
                'Who is paid, on trial, waiting for Direct Debit, overdue or suspended. Open one for its Billing status.',
                'Who is paid, on trial, overdue or suspended. Open one for its Billing status.',
            )}
            flush
        >
            <div className="flex flex-wrap gap-2 px-5 py-4 sm:px-6">
                <Chip active={data.group === null} label="All" count={data.total} onClick={() => filter(null)} />
                {FILTERS.filter((item) => !(manualCollection() && item.value === 'waitingForDirectDebit')).map((item) => (
                    <Chip
                        key={item.value}
                        active={data.group === item.value}
                        label={item.label}
                        count={data.counts[item.value] ?? 0}
                        tone={item.tone}
                        onClick={() => filter(item.value)}
                    />
                ))}
            </div>
            {data.rows.length === 0 ? (
                <EmptyState
                    icon={Users}
                    size="sm"
                    title="No business in this state"
                    body="Pick another filter to see the others."
                    className="m-5 mt-0"
                />
            ) : (
                <ul className="divide-y border-t">
                    {data.rows.map((row) => (
                        <li key={row.id}>
                            <Link
                                href={route('admin.tenants.show', { company: row.id, tab: 'billing' })}
                                className="hover:bg-muted/50 flex items-center gap-3 px-5 py-3 sm:px-6"
                            >
                                <span className="min-w-0 flex-1 leading-tight">
                                    <span className="flex flex-wrap items-center gap-2">
                                        <span className="truncate text-sm font-medium">{row.name}</span>
                                        {row.demo && <StatusPill tone="violet">Demo</StatusPill>}
                                    </span>
                                    <span className="text-muted-foreground mt-0.5 block truncate text-xs">{row.planType ?? 'No plan'}</span>
                                </span>
                                <span className="hidden max-w-[55%] text-right sm:block">
                                    <StatusPill tone={row.tone} className="h-auto py-1 whitespace-normal">
                                        {row.headline}
                                    </StatusPill>
                                </span>
                                <span className="sm:hidden">
                                    <StatusPill tone={row.tone}>{FILTERS.find((item) => item.value === row.group)?.label ?? 'Cancelled'}</StatusPill>
                                </span>
                                <ChevronRight className="text-muted-foreground size-4 shrink-0" aria-hidden />
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
            {data.truncated && (
                <p className="text-muted-foreground border-t px-5 py-3 text-xs sm:px-6">Showing the first 100. Filter by state to narrow it down.</p>
            )}
        </SectionCard>
    );
}

export default BillingStatesCard;
