import AppLogoIcon from '@/components/app-logo-icon';
import BrandLogo from '@/components/brand-logo';
import { SidebarNav, type ShellNavGroup } from '@/components/shell/sidebar-nav';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarRail } from '@/components/ui/sidebar';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';

/**
 * Top of the sidebar: the full Switch & Save logo (never cropped or stretched) linking to the dashboard, with the
 * area label under it ("Super admin console" / "Business portal"). Just the round mark when collapsed.
 */
export function SidebarLogo({ href, label }: { href: string; label: string }) {
    return (
        <SidebarHeader className="gap-1 px-5 pt-5 pb-3 group-data-[collapsible=icon]:items-center group-data-[collapsible=icon]:px-2">
            <Link
                href={href}
                prefetch
                className="focus-visible:ring-sidebar-ring -mx-1 block rounded-lg px-1 py-1 outline-none group-data-[collapsible=icon]:mx-0 group-data-[collapsible=icon]:p-0.5 focus-visible:ring-2"
            >
                <span className="block group-data-[collapsible=icon]:hidden">
                    <BrandLogo className="h-auto w-[196px]" alt="" />
                </span>
                <AppLogoIcon className="hidden size-9 group-data-[collapsible=icon]:block" alt="" />
                <span className="sr-only">Switch &amp; Save dashboard</span>
            </Link>
            <p className="text-sidebar-muted pl-0.5 text-xs font-medium group-data-[collapsible=icon]:hidden">{label}</p>
        </SidebarHeader>
    );
}

interface ShellSidebarProps {
    /** Where the logo links (the area's dashboard). */
    homeHref: string;
    /** Small label under the logo, e.g. "Super admin console". */
    areaLabel: string;
    /** Main navigation, scrolls when tall. */
    groups: ShellNavGroup[];
    /** Groups pinned near the bottom (Settings). */
    pinned?: ShellNavGroup[];
    /** Optional block between the logo and the navigation, e.g. the business name in the tenant portal. */
    header?: ReactNode;
}

/**
 * The white full-height sidebar: the logo and area label at the top, grouped nav, pinned Settings near the bottom
 * (no footer card: the logo at the top is the brand). Collapses to icons on desktop (rail); a sheet on phones.
 */
export function ShellSidebar({ homeHref, areaLabel, groups, pinned = [], header }: ShellSidebarProps) {
    return (
        <Sidebar collapsible="icon" className="border-sidebar-border top-(--shell-banner) h-[calc(100svh-var(--shell-banner))]">
            <SidebarLogo href={homeHref} label={areaLabel} />
            {header}
            <SidebarContent className="gap-0 py-2">
                <SidebarNav groups={groups} />
            </SidebarContent>
            {pinned.length > 0 && (
                <SidebarFooter className="gap-0 p-0 pb-3">
                    <div className="border-sidebar-border mx-3 border-t pt-1 group-data-[collapsible=icon]:mx-2">
                        <SidebarNav groups={pinned} className="px-0" />
                    </div>
                </SidebarFooter>
            )}
            <SidebarRail />
        </Sidebar>
    );
}

export default ShellSidebar;
