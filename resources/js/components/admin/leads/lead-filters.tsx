import { useTableQuery } from '@/components/shared/data-table';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectSeparator, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatNumber } from '@/lib/country';
import { cn } from '@/lib/utils';
import { UserRound, X } from 'lucide-react';
import { type LeadIndexProps } from './types';

export const LEAD_INDEX_ONLY = ['leads', 'board', 'filters', 'search', 'counts', 'mine', 'stats', 'view'];

const followUpOptions = [
    { value: 'due', label: 'Due by today' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'week', label: 'Next 7 days' },
    { value: 'none', label: 'No follow-up' },
] as const;

type FiltersProps = Pick<LeadIndexProps, 'filters' | 'counts' | 'statuses' | 'options' | 'mine' | 'view'>;

function Count({ value }: { value: number | undefined }) {
    return <span className="text-muted-foreground ml-1 tabular-nums">({formatNumber(value ?? 0)})</span>;
}

/** Quick "My leads" toggle, then status (list only), source, assigned and follow-up filters. All live in the URL. */
export function LeadFilters({ filters, counts, statuses, options, mine, view }: FiltersProps) {
    const { update } = useTableQuery({ only: LEAD_INDEX_ONLY });
    const onlyMine = filters.assigned === 'me';
    const active = Boolean(filters.status || filters.source || filters.assigned || filters.followUp);

    return (
        <>
            <Button
                type="button"
                variant="outline"
                aria-pressed={onlyMine}
                onClick={() => update({ assigned: onlyMine ? undefined : 'me', page: 1 })}
                className={cn(
                    'h-9 justify-start font-normal',
                    onlyMine && 'border-primary/40 bg-primary-soft text-accent-foreground hover:bg-primary-soft',
                )}
            >
                <UserRound className={cn(onlyMine ? 'text-primary' : 'text-muted-foreground')} />
                My leads
                <span className="text-muted-foreground tabular-nums">{formatNumber(mine)}</span>
            </Button>

            {view === 'list' && (
                <Select value={filters.status ?? 'all'} onValueChange={(next) => update({ status: next === 'all' ? undefined : next, page: 1 })}>
                    <SelectTrigger className="h-9 w-full sm:w-44" aria-label="Filter by status">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All statuses</SelectItem>
                        <SelectItem value="open">
                            Open
                            <Count value={counts.open} />
                        </SelectItem>
                        <SelectSeparator />
                        {statuses.map((status) => (
                            <SelectItem key={status.value} value={status.value}>
                                {status.label}
                                <Count value={counts[status.value]} />
                            </SelectItem>
                        ))}
                        <SelectSeparator />
                        <SelectItem value="archived">
                            Archived
                            <Count value={counts.archived} />
                        </SelectItem>
                    </SelectContent>
                </Select>
            )}

            <Select value={filters.source ?? 'all'} onValueChange={(next) => update({ source: next === 'all' ? undefined : next, page: 1 })}>
                <SelectTrigger className="h-9 w-full sm:w-36" aria-label="Filter by source">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All sources</SelectItem>
                    {options.sources.map((source) => (
                        <SelectItem key={source.value} value={source.value}>
                            {source.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <Select value={filters.assigned ?? 'all'} onValueChange={(next) => update({ assigned: next === 'all' ? undefined : next, page: 1 })}>
                <SelectTrigger className="h-9 w-full sm:w-40" aria-label="Filter by who it is assigned to">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">Anyone</SelectItem>
                    <SelectItem value="me">Assigned to me</SelectItem>
                    <SelectItem value="none">Unassigned</SelectItem>
                    {options.admins.length > 0 && <SelectSeparator />}
                    {options.admins.map((admin) => (
                        <SelectItem key={admin.value} value={admin.value}>
                            {admin.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            <Select value={filters.followUp ?? 'all'} onValueChange={(next) => update({ followUp: next === 'all' ? undefined : next, page: 1 })}>
                <SelectTrigger className="h-9 w-full sm:w-40" aria-label="Filter by follow-up">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">Any follow-up</SelectItem>
                    {followUpOptions.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            {active && (
                <Button
                    type="button"
                    variant="ghost"
                    className="text-muted-foreground h-9"
                    onClick={() => update({ status: undefined, source: undefined, assigned: undefined, followUp: undefined, page: 1 })}
                >
                    <X />
                    Clear filters
                </Button>
            )}
        </>
    );
}
