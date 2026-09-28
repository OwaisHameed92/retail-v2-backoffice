import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import { useState, type CSSProperties, type ReactNode } from 'react';

interface ShellFrameProps {
    /** localStorage key that remembers whether the sidebar is expanded. */
    storageKey: string;
    /** The Topbar. */
    header: ReactNode;
    /** Strip above the top bar (e.g. the "Viewing as" impersonation banner). */
    banner?: ReactNode;
    /** Height of the banner when shown, e.g. "2.5rem". */
    bannerHeight?: string;
    /** The ShellSidebar. */
    sidebar: ReactNode;
    className?: string;
    children: ReactNode;
}

/**
 * The v2 frame shared by the admin and business layouts: a fixed dark top bar (64px) with the dark sidebar
 * below it on the left, the page on the light canvas. Exposes `--shell-top` (and `--app-header-height` for
 * sticky table headers) as the height of everything fixed above the content.
 */
export function ShellFrame({ storageKey, header, banner, bannerHeight, sidebar, className, children }: ShellFrameProps) {
    const [isOpen, setIsOpen] = useState(() => (typeof window !== 'undefined' ? localStorage.getItem(storageKey) !== 'false' : true));

    const handleOpenChange = (open: boolean) => {
        setIsOpen(open);

        if (typeof window !== 'undefined') {
            localStorage.setItem(storageKey, String(open));
        }
    };

    const top = banner && bannerHeight ? `calc(4rem + ${bannerHeight})` : '4rem';

    return (
        <SidebarProvider open={isOpen} onOpenChange={handleOpenChange} style={{ '--shell-top': top, '--app-header-height': top } as CSSProperties}>
            <div className="fixed inset-x-0 top-0 z-40">
                {banner}
                {header}
            </div>
            {sidebar}
            <SidebarInset className={cn('min-w-0 pt-(--shell-top)', className)}>{children}</SidebarInset>
        </SidebarProvider>
    );
}

export default ShellFrame;
