import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type KeyboardEvent, type ReactNode } from 'react';

export interface PageTab {
    label: string;
    /** Link tabs: each tab is its own URL (preferred for top-level sections). */
    href?: string;
    /** Button tabs: in-page panels, matched against `value`. */
    value?: string;
    /** Marks a link tab as current. Button tabs use `value` instead. */
    active?: boolean;
    count?: number;
    /** Extra element after the label, e.g. a warning Badge. */
    badge?: ReactNode;
}

interface PageTabsProps {
    tabs: PageTab[];
    /** Accessible name, e.g. "Tenant sections". */
    label: string;
    /** Current value for button tabs. */
    value?: string;
    onChange?: (value: string) => void;
    className?: string;
}

const tabClass = (active: boolean) =>
    cn(
        'relative -mb-px inline-flex h-10 shrink-0 items-center gap-2 border-b-2 px-1 text-sm font-medium whitespace-nowrap transition-colors duration-150',
        'focus-visible:ring-ring/40 rounded-t-sm outline-none focus-visible:ring-2',
        active ? 'border-primary text-foreground' : 'text-muted-foreground hover:border-border-strong hover:text-foreground border-transparent',
    );

function TabCount({ count, active }: { count: number; active: boolean }) {
    return (
        <span
            className={cn(
                'rounded-full px-1.5 py-px text-[11px] leading-4 font-medium tabular-nums',
                active ? 'bg-primary-soft text-accent-foreground' : 'bg-muted text-muted-foreground',
            )}
        >
            {count}
        </span>
    );
}

/**
 * Underline tabs under a page header. Link tabs (each its own URL) or button tabs (role="tab", arrow keys)
 * for in-page panels; panels use id `tab-panel-<value>`. Scrolls sideways on phones.
 */
export function PageTabs({ tabs, label, value, onChange, className }: PageTabsProps) {
    const buttons = tabs.every((tab) => tab.href === undefined);

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (!buttons || !onChange) {
            return;
        }
        const index = tabs.findIndex((tab) => tab.value === value);
        const delta = event.key === 'ArrowRight' ? 1 : event.key === 'ArrowLeft' ? -1 : 0;
        if (delta === 0) {
            return;
        }
        event.preventDefault();
        const next = tabs[(index + delta + tabs.length) % tabs.length];
        onChange(next.value ?? '');
        document.getElementById(`tab-${next.value}`)?.focus();
    };

    return (
        <div className={cn('border-b', className)}>
            <div
                role={buttons ? 'tablist' : undefined}
                aria-label={label}
                onKeyDown={onKeyDown}
                className="scrollbar-none -mx-4 flex gap-6 overflow-x-auto px-4 sm:mx-0 sm:px-0"
            >
                {tabs.map((tab) => {
                    if (!buttons && tab.href !== undefined) {
                        const active = !!tab.active;

                        return (
                            <Link key={tab.href} href={tab.href} prefetch aria-current={active ? 'page' : undefined} className={tabClass(active)}>
                                {tab.label}
                                {tab.count !== undefined && <TabCount count={tab.count} active={active} />}
                                {tab.badge}
                            </Link>
                        );
                    }

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
                            onClick={() => onChange?.(tab.value ?? '')}
                            className={tabClass(selected)}
                        >
                            {tab.label}
                            {tab.count !== undefined && <TabCount count={tab.count} active={selected} />}
                            {tab.badge}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

export default PageTabs;
