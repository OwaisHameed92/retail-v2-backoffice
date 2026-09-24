import { AdminSidebar } from '@/components/admin/admin-sidebar';
import { AdminTopbar } from '@/components/admin/admin-topbar';
import { LicenceKeysDialog } from '@/components/admin/licences/licence-keys-dialog';
import { Toaster } from '@/components/shared/toaster';
import { type TopbarCrumb } from '@/components/shell/topbar';
import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import { useState, type CSSProperties, type ReactNode } from 'react';

const STORAGE_KEY = 'admin-sidebar';

interface AdminLayoutProps {
    children: ReactNode;
    /** Top-bar trail. Defaults to the sidebar group and section ("Customers › Tenants"); detail pages add the record. */
    breadcrumbs?: TopbarCrumb[];
    /** `wide` drops the max width for dense tables; `narrow` centres forms at max-w-4xl. */
    width?: 'default' | 'wide' | 'narrow';
}

/**
 * Layout for the super admin area (/admin): grouped collapsible sidebar (sheet on mobile), sticky top bar with
 * breadcrumbs, global search and account menu, and the page in a centred container. Mounts the one toaster and
 * the one "Licence key created" dialog.
 */
export default function AdminLayout({ children, breadcrumbs, width = 'default' }: AdminLayoutProps) {
    const [isOpen, setIsOpen] = useState(() => (typeof window !== 'undefined' ? localStorage.getItem(STORAGE_KEY) !== 'false' : true));

    const handleOpenChange = (open: boolean) => {
        setIsOpen(open);

        if (typeof window !== 'undefined') {
            localStorage.setItem(STORAGE_KEY, String(open));
        }
    };

    return (
        <SidebarProvider open={isOpen} onOpenChange={handleOpenChange}>
            <AdminSidebar />
            <SidebarInset className="min-w-0" style={{ '--app-header-height': '3.5rem' } as CSSProperties}>
                <AdminTopbar breadcrumbs={breadcrumbs} />
                <div
                    className={cn(
                        'mx-auto flex w-full flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8',
                        width === 'default' && 'max-w-7xl',
                        width === 'narrow' && 'max-w-4xl',
                        width === 'wide' && 'max-w-[100rem]',
                    )}
                >
                    {children}
                </div>
            </SidebarInset>
            <Toaster />
            <LicenceKeysDialog />
        </SidebarProvider>
    );
}
