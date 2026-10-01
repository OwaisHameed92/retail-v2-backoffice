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
                className="focus-visible:ring-sidebar-ring -mx-1 block rounded-lg px-1 py-1 outline-none focus-visible:ring-2 group-data-[collapsible=icon]:mx-0 group-data-[collapsible=icon]:p-0.5"
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

/** Bottom of the sidebar: a soft card with the mark, "Switch & Save" and the tagline. Just the mark when collapsed. */
export function SidebarBrandFooter() {
    return (
        <div className="px-3 pt-2 pb-4 group-data-[collapsible=icon]:hidden">
            <div className="bg-subtle border-sidebar-border flex items-center gap-2.5 rounded-xl border px-2.5 py-2.5 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:border-0 group-data-[collapsible=icon]:bg-transparent group-data-[collapsible=icon]:p-0">
                <AppLogoIcon className="size-9 shrink-0" alt="" />
                <div className="min-w-0 leading-tight group-data-[collapsible=icon]:hidden">
                    <p className="truncate text-[15px] font-semibold tracking-[-0.01em]">
                        <span className="text-sidebar-accent-foreground">Switch</span> <span className="text-sidebar-active-foreground">&amp; Save</span>
                    </p>
                    <p className="text-sidebar-muted text-[11px] leading-4">Smarter EPOS. Bigger growth.</p>
                </div>
            </div>
        </div>
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
 * and the brand card at the very bottom. Collapses to icons on desktop; a sheet on phones.
 */
export function ShellSidebar({ homeHref, areaLabel, groups, pinned = [], header }: ShellSidebarProps) {
    return (
        <Sidebar collapsible="icon" className="border-sidebar-border top-(--shell-banner) h-[calc(100svh-var(--shell-banner))]">
            <SidebarLogo href={homeHref} label={areaLabel} />
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
