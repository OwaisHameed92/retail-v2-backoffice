import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import { useState, type CSSProperties, type ReactNode } from 'react';

interface ShellFrameProps {
    /** localStorage key that remembers whether the sidebar is expanded. */
    storageKey: string;
    /** The Topbar. */
    header: ReactNode;
    /** Strip above everything (e.g. the "Viewing as" impersonation banner). */
    banner?: ReactNode;
    /** Height of the banner when shown, e.g. "2.5rem". */
    bannerHeight?: string;
    /** The ShellSidebar. */
    sidebar: ReactNode;
    className?: string;
    children: ReactNode;
}

/**
 * The v2 frame shared by the admin and business layouts (light theme, reference-light-final.webp): the white
 * sidebar runs full height on the left with the logo at its top; the 64px light top bar sticks to the top of the
 * content column on its right; the page sits on the light canvas below. An optional banner is fixed above both.
 * Exposes `--shell-banner` (banner height) and `--shell-top` / `--app-header-height` (banner + top bar) for sticky
 * table headers.
 */
export function ShellFrame({ storageKey, header, banner, bannerHeight, sidebar, className, children }: ShellFrameProps) {
    const [isOpen, setIsOpen] = useState(() => (typeof window !== 'undefined' ? localStorage.getItem(storageKey) !== 'false' : true));

    const handleOpenChange = (open: boolean) => {
        setIsOpen(open);

        if (typeof window !== 'undefined') {
            localStorage.setItem(storageKey, String(open));
        }
    };

    const bannerTop = banner && bannerHeight ? bannerHeight : '0px';
    const top = banner && bannerHeight ? `calc(4rem + ${bannerHeight})` : '4rem';

    return (
        <SidebarProvider
            open={isOpen}
            onOpenChange={handleOpenChange}
            style={{ '--shell-banner': bannerTop, '--shell-top': top, '--app-header-height': top } as CSSProperties}
        >
            {banner && <div className="fixed inset-x-0 top-0 z-50">{banner}</div>}
            {sidebar}
            <SidebarInset className={cn('min-w-0 pt-(--shell-banner)', className)}>
                <div className="sticky top-(--shell-banner) z-30">{header}</div>
                {children}
            </SidebarInset>
        </SidebarProvider>
    );
}

export default ShellFrame;
