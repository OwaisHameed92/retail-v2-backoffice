import { AdminSidebar } from '@/components/admin/admin-sidebar';
import { AdminTopbar } from '@/components/admin/admin-topbar';
import { Toaster } from '@/components/shared/toaster';
import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar';
import { useState, type CSSProperties, type ReactNode } from 'react';

const STORAGE_KEY = 'admin-sidebar';

/**
 * Layout for the super admin area (/admin): collapsible sidebar (sheet on mobile) + top bar with
 * global search and the admin's account menu.
 */
export default function AdminLayout({ children }: { children: ReactNode }) {
    const [isOpen, setIsOpen] = useState(() => (typeof window !== 'undefined' ? localStorage.getItem(STORAGE_KEY) !== 'false' : true));

    const handleOpenChange = (open: boolean) => {
        setIsOpen(open);

        if (typeof window !== 'undefined') {
            localStorage.setItem(STORAGE_KEY, String(open));
        }
    };

    return (
        <SidebarProvider open={isOpen} onOpenChange={handleOpenChange} style={{ '--sidebar-width': '220px' } as CSSProperties}>
            <AdminSidebar />
            <SidebarInset className="min-w-0">
                <AdminTopbar />
                <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">{children}</div>
            </SidebarInset>
            <Toaster />
        </SidebarProvider>
    );
}
