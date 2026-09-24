import AppLogoIcon from '@/components/app-logo-icon';
import { SidebarHeader } from '@/components/ui/sidebar';
import { Link } from '@inertiajs/react';

interface SidebarBrandProps {
    href: string;
    /** Second line under the wordmark: "Admin" or the company name. */
    subtitle?: string | null;
    /** Small pill after the subtitle, e.g. "Staff". */
    tag?: string;
}

/** Sidebar header: the "S" mark, "Switch & Save" and a subtitle, 56px tall to line up with the top bar. */
export function SidebarBrand({ href, subtitle, tag }: SidebarBrandProps) {
    return (
        <SidebarHeader className="border-sidebar-border h-14 justify-center border-b px-3 py-0 group-data-[collapsible=icon]:px-2">
            <Link
                href={href}
                prefetch
                className="focus-visible:ring-sidebar-ring flex min-w-0 items-center gap-2.5 rounded-md px-1 py-1 outline-none group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0 focus-visible:ring-2"
            >
                <AppLogoIcon className="size-8 shrink-0" alt="" />
                <span className="grid min-w-0 flex-1 leading-tight group-data-[collapsible=icon]:hidden">
                    <span className="text-sidebar-accent-foreground truncate text-[15px] font-semibold tracking-[-0.01em]">
                        Switch <span className="text-brand-green">&amp;</span> Save
                    </span>
                    {subtitle && (
                        <span className="text-sidebar-muted flex min-w-0 items-center gap-1.5 text-xs">
                            <span className="truncate">{subtitle}</span>
                            {tag && (
                                <span className="bg-primary-soft text-accent-foreground shrink-0 rounded px-1 text-[10px] leading-4 font-semibold tracking-wide uppercase">
                                    {tag}
                                </span>
                            )}
                        </span>
                    )}
                </span>
                <span className="sr-only">Switch &amp; Save home</span>
            </Link>
        </SidebarHeader>
    );
}

export default SidebarBrand;
