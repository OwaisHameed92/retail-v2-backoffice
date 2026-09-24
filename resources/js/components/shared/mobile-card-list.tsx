import { cn } from '@/lib/utils';
import { ChevronRight } from 'lucide-react';
import { type KeyboardEvent, type ReactNode } from 'react';

export interface MobileCardField {
    label: string;
    value: ReactNode;
}

export interface MobileCardContent {
    /** Top-left: usually an EntityCell or the name. */
    title: ReactNode;
    /** Top-right: usually a StatusBadge. */
    aside?: ReactNode;
    /** Label/value pairs in a two-column grid under the title. */
    fields?: MobileCardField[];
    /** Row actions (RowActions) shown bottom-right. */
    actions?: ReactNode;
}

interface MobileCardListProps<T> {
    items: T[];
    getKey: (item: T, index: number) => string;
    render: (item: T) => MobileCardContent;
    onItemClick?: (item: T) => void;
    className?: string;
}

/**
 * Phone layout for lists: one card per row with the title, a status and the key fields, instead of a table
 * that scrolls sideways. DataTable uses it automatically below the `md` breakpoint.
 */
export function MobileCardList<T>({ items, getKey, render, onItemClick, className }: MobileCardListProps<T>) {
    const onKey = (event: KeyboardEvent<HTMLLIElement>, item: T) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            onItemClick?.(item);
        }
    };

    return (
        <ul className={cn('divide-y', className)}>
            {items.map((item, index) => {
                const card = render(item);

                return (
                    <li
                        key={getKey(item, index)}
                        onClick={onItemClick ? () => onItemClick(item) : undefined}
                        onKeyDown={onItemClick ? (event) => onKey(event, item) : undefined}
                        tabIndex={onItemClick ? 0 : undefined}
                        className={cn(
                            'flex flex-col gap-3 px-4 py-3.5',
                            onItemClick && 'active:bg-muted/60 focus-visible:bg-muted/50 cursor-pointer transition-colors outline-none',
                        )}
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0 flex-1 text-sm">{card.title}</div>
                            <div className="flex shrink-0 items-center gap-1">
                                {card.aside}
                                {onItemClick && !card.actions && <ChevronRight className="text-muted-foreground/60 size-4" aria-hidden />}
                            </div>
                        </div>
                        {(card.fields?.length || card.actions) && (
                            <div className="flex items-end justify-between gap-3">
                                {card.fields && card.fields.length > 0 && (
                                    <dl className="grid min-w-0 flex-1 grid-cols-2 gap-x-4 gap-y-2 text-[13px]">
                                        {card.fields.map((field) => (
                                            <div key={field.label} className="min-w-0">
                                                <dt className="text-muted-foreground text-2xs font-medium tracking-[0.04em] uppercase">
                                                    {field.label}
                                                </dt>
                                                <dd className="text-foreground mt-0.5 truncate">{field.value}</dd>
                                            </div>
                                        ))}
                                    </dl>
                                )}
                                {card.actions && <div className="-mr-1.5 shrink-0">{card.actions}</div>}
                            </div>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

export default MobileCardList;
