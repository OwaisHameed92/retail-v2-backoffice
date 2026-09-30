import { useTableQuery } from '@/components/shared/data-table';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { X } from 'lucide-react';
import { type Option, type ProductIndexProps } from './types';

export const PRODUCT_INDEX_ONLY = ['products', 'filters', 'counts'];

const number = new Intl.NumberFormat('en-GB');

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
    all?: string;
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
                {all && <SelectItem value="all">{all}</SelectItem>}
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/** Status (active by default), department and category (of the chosen department). */
export function ProductFilters({ filters, options, counts }: Pick<ProductIndexProps, 'filters' | 'options' | 'counts'>) {
    const { update } = useTableQuery({ only: PRODUCT_INDEX_ONLY });
    const categories = options.categories.filter((c) => c.parentId === null && (!filters.department || c.departmentId === filters.department));
    const active = Boolean(filters.department || filters.category) || filters.status !== 'active';

    return (
        <>
            <FilterSelect
                value={filters.status}
                onChange={(value) => update({ status: value ?? 'active', page: 1 })}
                options={[
                    { value: 'active', label: `Active (${number.format(counts.active)})` },
                    { value: 'archived', label: `Archived (${number.format(counts.archived)})` },
                    { value: 'all', label: 'Active and archived' },
                ]}
                label="Filter by status"
            />
            {options.departments.length > 0 && (
                <FilterSelect
                    value={filters.department ?? 'all'}
                    onChange={(value) => update({ department: value, category: undefined, page: 1 })}
                    all="All departments"
                    options={options.departments}
                    label="Filter by department"
                    width="sm:w-48"
                />
            )}
            {categories.length > 0 && (
                <FilterSelect
                    value={filters.category ?? 'all'}
                    onChange={(value) => update({ category: value, page: 1 })}
                    all="All categories"
                    options={categories}
                    label="Filter by category"
                    width="sm:w-48"
                />
            )}
            {active && (
                <Button
                    type="button"
                    variant="ghost"
                    className="text-muted-foreground h-9"
                    onClick={() => update({ status: undefined, department: undefined, category: undefined, page: 1 })}
                >
                    <X />
                    Clear filters
                </Button>
            )}
        </>
    );
}
