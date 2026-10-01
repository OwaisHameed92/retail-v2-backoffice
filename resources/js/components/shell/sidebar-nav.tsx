import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { ChevronDown, type LucideIcon } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

export interface ShellNavItem {
    title: string;
    icon: LucideIcon;
    /** Omit (or set `soon`) for a section that is not built yet: shown muted with a "Soon" tag, not a link. */
    href?: string;
    active?: boolean;
    soon?: boolean;
    /** Small count shown as a green pill, e.g. new leads. Hidden when 0 or undefined. */
    count?: number;
    /** Green dot for a live status (e.g. Sync & jobs running). Only set it from real data. */
    live?: boolean;
    /** Custom right-hand content; use `count` for plain numbers. */
    badge?: ReactNode;
}

export interface ShellNavGroup {
    /** Small uppercase label. Omit for the first (overview) group. */
    label?: string;
    items: ShellNavItem[];
    /**
     * The label becomes a toggle that shows or hides the group. `false` (default) keeps it always open; `'open'` or
     * `'closed'` is the first-visit state. A group holding the current page is always open; the choice is remembered.
     */
    collapsible?: false | 'open' | 'closed';
}

const STORAGE_KEY = 'sidebar:groups';

function readStored(): Record<string, boolean> {
    try {
        return JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? '{}') as Record<string, boolean>;
    } catch {
        return {};
    }
}

function store(label: string, open: boolean) {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify({ ...readStored(), [label]: open }));
    } catch {
        // Private mode or blocked storage: the group still toggles for this page.
    }
}

function SoonTag() {
    return (
        <span className="bg-muted text-sidebar-muted ml-auto rounded-md px-1.5 py-0.5 text-[10px] leading-3 font-medium group-data-[collapsible=icon]:hidden">
            Soon
        </span>
    );
}

function ItemExtras({ item }: { item: ShellNavItem }) {
    if (!item.badge && !item.count && !item.live) {
        return null;
    }

    return (
        <span className="ml-auto flex items-center gap-2 group-data-[collapsible=icon]:hidden">
            {item.badge}
            {item.live && (
                <span className="relative flex size-2" aria-label="Live" role="img">
                    <span className="bg-sidebar-ring absolute inset-0 animate-ping rounded-full opacity-40" />
                    <span className="bg-sidebar-ring relative size-2 rounded-full" />
                </span>
            )}
            {!!item.count && (
                <span className="bg-sidebar-primary text-sidebar-primary-foreground inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-[11px] font-semibold tabular-nums">
                    {item.count > 99 ? '99+' : item.count}
                </span>
            )}
        </span>
    );
}

function NavItems({ items }: { items: ShellNavItem[] }) {
    return (
        <SidebarMenu className="gap-0.5">
            {items.map((item) => (
                <SidebarMenuItem key={item.title}>
                    {item.href && !item.soon ? (
                        <SidebarMenuButton asChild isActive={!!item.active} tooltip={item.title}>
                            <Link href={item.href} prefetch aria-current={item.active ? 'page' : undefined}>
                                <item.icon />
                                <span className="truncate">{item.title}</span>
                                <ItemExtras item={item} />
                            </Link>
                        </SidebarMenuButton>
                    ) : (
                        <SidebarMenuButton
                            type="button"
                            aria-disabled="true"
                            tooltip={`${item.title} (soon)`}
                            className="text-sidebar-muted hover:text-sidebar-muted cursor-default font-normal hover:bg-transparent active:bg-transparent [&>svg]:opacity-70"
                        >
                            <item.icon />
                            <span className="truncate">{item.title}</span>
                            <SoonTag />
                        </SidebarMenuButton>
                    )}
                </SidebarMenuItem>
            ))}
        </SidebarMenu>
    );
}

function CollapsibleGroup({ group, className }: { group: ShellNavGroup & { label: string }; className?: string }) {
    const { state, isMobile } = useSidebar();
    const hasActive = group.items.some((item) => item.active);
    const [open, setOpen] = useState(group.collapsible !== 'closed');

    // The remembered choice is read after mount so server and client render the same first frame.
    useEffect(() => {
        const stored = readStored()[group.label];
        if (stored !== undefined) {
            setOpen(stored);
        }
    }, [group.label]);
    // Icon-only sidebar: labels are hidden, so every item stays reachable.
    const iconOnly = state === 'collapsed' && !isMobile;
    const shown = open || hasActive || iconOnly;

    const toggle = (next: boolean) => {
        setOpen(next);
        store(group.label, next);
    };

    return (
        <Collapsible open={shown} onOpenChange={toggle} asChild>
            <SidebarGroup className={cn('px-3 py-1.5', className)}>
                <SidebarGroupLabel asChild className="h-7 px-2.5">
                    <CollapsibleTrigger
                        disabled={hasActive}
                        tabIndex={iconOnly ? -1 : undefined}
                        className="hover:text-sidebar-accent-foreground w-full cursor-pointer justify-between transition-colors disabled:cursor-default"
                    >
                        {group.label}
                        {!hasActive && (
                            <ChevronDown className={cn('size-3.5! transition-transform duration-200', !shown && '-rotate-90')} aria-hidden />
                        )}
                    </CollapsibleTrigger>
                </SidebarGroupLabel>
                <CollapsibleContent>
                    <NavItems items={group.items} />
                </CollapsibleContent>
            </SidebarGroup>
        </Collapsible>
    );
}

/**
 * Grouped navigation for the white sidebar, shared by the admin and business layouts: small grey uppercase
 * group labels (optionally collapsible), a soft green pill for the current item, green count pills, live dots, subtle "Soon" items that
 * are not links. Empty groups are hidden. Collapses to icons with tooltips.
 */
export function SidebarNav({ groups, className }: { groups: ShellNavGroup[]; className?: string }) {
    return (
        <>
            {groups
                .filter((group) => group.items.length > 0)
                .map((group, index) =>
                    group.label && group.collapsible ? (
                        <CollapsibleGroup key={group.label} group={{ ...group, label: group.label }} className={className} />
                    ) : (
                        <SidebarGroup key={group.label ?? `group-${index}`} className={cn('px-3 py-1.5', className)}>
                            {group.label && <SidebarGroupLabel className="h-7 px-2.5">{group.label}</SidebarGroupLabel>}
                            <NavItems items={group.items} />
                        </SidebarGroup>
                    ),
                )}
        </>
    );
}

export default SidebarNav;
