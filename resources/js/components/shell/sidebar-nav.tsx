import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

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
}

function SoonTag() {
    return (
        <span className="border-sidebar-border text-sidebar-muted ml-auto rounded-full border px-1.5 py-px text-[10px] leading-3.5 font-medium group-data-[collapsible=icon]:hidden">
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
                <span className="bg-primary text-sidebar-primary-foreground inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-[11px] font-semibold tabular-nums">
                    {item.count > 99 ? '99+' : item.count}
                </span>
            )}
        </span>
    );
}

/**
 * Grouped navigation for the dark sidebar, shared by the admin and business layouts: small uppercase muted
 * group labels, chrome-active current item, count pills, live dots, muted "Soon" items that are not links.
 * Collapses to icons with tooltips.
 */
export function SidebarNav({ groups, className }: { groups: ShellNavGroup[]; className?: string }) {
    return (
        <>
            {groups
                .filter((group) => group.items.length > 0)
                .map((group, index) => (
                    <SidebarGroup key={group.label ?? `group-${index}`} className={cn('px-3 py-2', className)}>
                        {group.label && <SidebarGroupLabel className="h-6 px-2.5">{group.label}</SidebarGroupLabel>}
                        <SidebarMenu className="gap-0.5">
                            {group.items.map((item) => (
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
                                            className="text-sidebar-muted/80 hover:text-sidebar-muted/80 cursor-default font-normal hover:bg-transparent active:bg-transparent [&>svg]:opacity-60"
                                        >
                                            <item.icon />
                                            <span className="truncate">{item.title}</span>
                                            <SoonTag />
                                        </SidebarMenuButton>
                                    )}
                                </SidebarMenuItem>
                            ))}
                        </SidebarMenu>
                    </SidebarGroup>
                ))}
        </>
    );
}

export default SidebarNav;
