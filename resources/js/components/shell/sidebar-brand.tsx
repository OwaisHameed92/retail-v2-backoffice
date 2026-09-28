import AppLogoIcon from '@/components/app-logo-icon';
import { SidebarNav, type ShellNavGroup } from '@/components/shell/sidebar-nav';
import { Sidebar, SidebarContent, SidebarFooter, SidebarRail } from '@/components/ui/sidebar';
import { type ReactNode } from 'react';

/** Bottom of the sidebar: the mark, "Switch & Save" and the tagline. Just the mark when collapsed. */
export function SidebarBrandFooter() {
    return (
        <div className="border-sidebar-border mx-3 flex items-center gap-3 border-t px-1 pt-4 pb-5 group-data-[collapsible=icon]:mx-2 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0">
            <AppLogoIcon className="size-9 shrink-0" alt="" />
            <div className="min-w-0 leading-tight group-data-[collapsible=icon]:hidden">
                <p className="truncate text-[15px] font-semibold tracking-[-0.01em]">
                    <span className="text-sidebar-accent-foreground">Switch</span> <span className="text-brand-green">&amp; Save</span>
                </p>
                <p className="text-sidebar-muted truncate text-[11px]">Smarter EPOS. Bigger growth.</p>
            </div>
        </div>
    );
}

interface ShellSidebarProps {
    /** Main navigation, scrolls when tall. */
    groups: ShellNavGroup[];
    /** Groups pinned near the bottom (Settings). */
    pinned?: ShellNavGroup[];
    /** Optional block above the navigation, e.g. the business name in the tenant portal. */
    header?: ReactNode;
}

/**
 * The dark full-height sidebar under the top bar: grouped nav, pinned Settings near the bottom and the logo +
 * tagline at the very bottom. Collapses to icons on desktop; a sheet on phones.
 */
export function ShellSidebar({ groups, pinned = [], header }: ShellSidebarProps) {
    return (
        <Sidebar collapsible="icon" className="top-(--shell-top) h-[calc(100svh-var(--shell-top))]">
            {header}
            <SidebarContent className="gap-0 py-2">
                <SidebarNav groups={groups} />
            </SidebarContent>
            <SidebarFooter className="gap-0 p-0">
                {pinned.length > 0 && (
                    <div className="border-sidebar-border mx-3 border-t pt-1 group-data-[collapsible=icon]:mx-2">
                        <SidebarNav groups={pinned} className="px-0" />
                    </div>
                )}
                <SidebarBrandFooter />
            </SidebarFooter>
            <SidebarRail />
        </Sidebar>
    );
}

export default ShellSidebar;
