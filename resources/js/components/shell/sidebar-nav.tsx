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
    /** Small count or status on the right, e.g. new leads. */
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

/**
 * Grouped sidebar navigation shared by the admin and tenant layouts: small muted group labels, brand-blue
 * active item, muted "Soon" items that are not links. Collapses to icons with tooltips.
 */
export function SidebarNav({ groups, className }: { groups: ShellNavGroup[]; className?: string }) {
    return (
        <>
            {groups
                .filter((group) => group.items.length > 0)
                .map((group, index) => (
                    <SidebarGroup key={group.label ?? `group-${index}`} className={cn('px-3 py-1.5', index === 0 && 'pt-3', className)}>
                        {group.label && <SidebarGroupLabel className="px-2">{group.label}</SidebarGroupLabel>}
                        <SidebarMenu className="gap-0.5">
                            {group.items.map((item) => (
                                <SidebarMenuItem key={item.title}>
                                    {item.href && !item.soon ? (
                                        <SidebarMenuButton asChild isActive={!!item.active} tooltip={item.title}>
                                            <Link href={item.href} prefetch aria-current={item.active ? 'page' : undefined}>
                                                <item.icon />
                                                <span>{item.title}</span>
                                                {item.badge && <span className="ml-auto group-data-[collapsible=icon]:hidden">{item.badge}</span>}
                                            </Link>
                                        </SidebarMenuButton>
                                    ) : (
                                        <SidebarMenuButton
                                            type="button"
                                            aria-disabled="true"
                                            tooltip={`${item.title} (soon)`}
                                            className="text-sidebar-muted/80 hover:text-sidebar-muted/80 cursor-default font-normal hover:bg-transparent active:bg-transparent [&>svg]:opacity-70"
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
