import { useTableQuery } from '@/components/shared/data-table';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatNumber } from '@/lib/country';
import { X } from 'lucide-react';
import { CONFLICT_INDEX_ONLY } from './format';
import { type ConflictIndexProps, type Option } from './types';

function FilterSelect({
    value,
    onChange,
    all,
    options,
    label,
    width = 'sm:w-44',
}: {
    value: string;
    onChange: (value: string | undefined) => void;
    all: string;
    options: Option[];
    label: string;
    width?: string;
}) {
    return (
        <Select value={value} onValueChange={(next) => onChange(next === 'all' ? undefined : next)}>
            <SelectTrigger className={`h-9 w-full ${width}`} aria-label={label}>
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="all">{all}</SelectItem>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/** Portal tab: status (open by default), kind and shop. Shop tab: resolution at the shop (waiting by default) and shop. */
export function ConflictFilters({ tab, filters, options, counts }: Pick<ConflictIndexProps, 'tab' | 'filters' | 'options' | 'counts'>) {
    const { update } = useTableQuery({ only: CONFLICT_INDEX_ONLY });
    const set = (key: string) => (value: string | undefined) => update({ [key]: value, page: 1 });
    const branches = options.branches.length > 1 && (
        <FilterSelect value={filters.branch ?? 'all'} onChange={set('branch')} all="All shops" options={options.branches} label="Filter by shop" />
    );

    if (tab === 'shop') {
        const active = Boolean(filters.branch) || filters.resolution !== 'pending';

        return (
            <>
                <FilterSelect
                    value={filters.resolution === 'pending' ? 'pending' : (filters.resolution ?? 'all')}
                    onChange={(value) => update({ resolution: value ?? 'all', page: 1 })}
                    all="Any outcome"
                    options={options.resolutions}
                    label="Filter by what the shop decided"
                    width="sm:w-52"
                />
                {branches}
                {active && (
                    <Button
                        type="button"
                        variant="ghost"
                        className="text-muted-foreground h-9"
                        onClick={() => update({ resolution: undefined, branch: undefined, page: 1 })}
                    >
                        <X />
                        Clear filters
                    </Button>
                )}
            </>
        );
    }

    const status: Option[] = [
        { value: 'open', label: `Needs review (${formatNumber(counts.open)})` },
        { value: 'resolved', label: `Resolved (${formatNumber(counts.resolved)})` },
    ];
    const active = Boolean(filters.kind || filters.branch) || filters.status !== 'open';

    return (
        <>
            <FilterSelect
                value={filters.status ?? 'open'}
                onChange={(value) => update({ status: value ?? 'all', page: 1 })}
                all="Any status"
                options={status}
                label="Filter by status"
            />
            <FilterSelect
                value={filters.kind ?? 'all'}
                onChange={set('kind')}
                all="Any reason"
                options={options.kinds}
                label="Filter by reason"
                width="sm:w-56"
            />
            {branches}
            {active && (
                <Button
                    type="button"
                    variant="ghost"
                    className="text-muted-foreground h-9"
                    onClick={() => update({ status: undefined, kind: undefined, branch: undefined, page: 1 })}
                >
                    <X />
                    Clear filters
                </Button>
            )}
        </>
    );
}
