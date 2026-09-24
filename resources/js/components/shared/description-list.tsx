import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

export interface DescriptionItem {
    label: string;
    /** Null/undefined/'' shows a muted "Not set". */
    value: ReactNode;
    /** Monospace value (ids, codes). */
    mono?: boolean;
    /** Span the full row in grid layout (addresses, notes). */
    wide?: boolean;
}

interface DescriptionListProps {
    items: DescriptionItem[];
    /** grid: label above value in 2–4 columns (summary cards). rows: label left, value right, hairline dividers (side panels). */
    layout?: 'grid' | 'rows';
    columns?: 2 | 3 | 4;
    className?: string;
}

function renderValue(item: DescriptionItem) {
    if (item.value === null || item.value === undefined || item.value === '') {
        return <span className="text-muted-foreground">Not set</span>;
    }

    return item.value;
}

/** Key facts on detail pages. Use `grid` inside a SectionCard for the summary, `rows` for a narrow side card. */
export function DescriptionList({ items, layout = 'grid', columns = 4, className }: DescriptionListProps) {
    if (layout === 'rows') {
        return (
            <dl className={cn('divide-y', className)}>
                {items.map((item) => (
                    <div key={item.label} className="grid grid-cols-[minmax(7rem,40%)_minmax(0,1fr)] gap-3 py-2.5 text-sm first:pt-0 last:pb-0">
                        <dt className="text-muted-foreground">{item.label}</dt>
                        <dd className={cn('text-foreground min-w-0 text-right break-words', item.mono && 'font-mono text-[13px]')}>
                            {renderValue(item)}
                        </dd>
                    </div>
                ))}
            </dl>
        );
    }

    return (
        <dl className={cn('grid gap-x-6 gap-y-5 sm:grid-cols-2', columns === 3 && 'lg:grid-cols-3', columns === 4 && 'lg:grid-cols-4', className)}>
            {items.map((item) => (
                <div key={item.label} className={cn('min-w-0 space-y-1', item.wide && 'sm:col-span-2')}>
                    <dt className="text-muted-foreground text-[13px]">{item.label}</dt>
                    <dd className={cn('text-foreground text-sm font-medium break-words', item.mono && 'font-mono text-[13px] font-normal')}>
                        {renderValue(item)}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

export default DescriptionList;
