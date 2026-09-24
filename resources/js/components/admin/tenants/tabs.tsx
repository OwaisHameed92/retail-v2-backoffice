import { cn } from '@/lib/utils';
import { type KeyboardEvent, type ReactNode } from 'react';

export interface TabItem<T extends string> {
    value: T;
    label: string;
    count?: number;
    badge?: ReactNode;
}

interface TabsProps<T extends string> {
    tabs: TabItem<T>[];
    value: T;
    onChange: (value: T) => void;
    label: string;
}

/** Accessible tab list (arrow keys move between tabs). Panels use id `tab-panel-<value>`. */
export function Tabs<T extends string>({ tabs, value, onChange, label }: TabsProps<T>) {
    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const index = tabs.findIndex((tab) => tab.value === value);
        const delta = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
        if (delta === 0) {
            return;
        }
        event.preventDefault();
        const next = tabs[(index + delta + tabs.length) % tabs.length];
        onChange(next.value);
        document.getElementById(`tab-${next.value}`)?.focus();
    };

    return (
        <div role="tablist" aria-label={label} onKeyDown={onKeyDown} className="-mx-4 flex gap-1 overflow-x-auto border-b px-4 md:mx-0 md:px-0">
            {tabs.map((tab) => {
                const selected = tab.value === value;

                return (
                    <button
                        key={tab.value}
                        id={`tab-${tab.value}`}
                        type="button"
                        role="tab"
                        aria-selected={selected}
                        aria-controls={`tab-panel-${tab.value}`}
                        tabIndex={selected ? 0 : -1}
                        onClick={() => onChange(tab.value)}
                        className={cn(
                            '-mb-px inline-flex shrink-0 items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap transition-colors',
                            'focus-visible:ring-ring focus-visible:rounded-t-md focus-visible:ring-2 focus-visible:outline-none',
                            selected ? 'border-primary text-foreground' : 'text-muted-foreground hover:text-foreground border-transparent',
                        )}
                    >
                        {tab.label}
                        {tab.count !== undefined && (
                            <span className="bg-muted text-muted-foreground rounded-full px-1.5 py-0.5 text-xs tabular-nums">{tab.count}</span>
                        )}
                        {tab.badge}
                    </button>
                );
            })}
        </div>
    );
}
