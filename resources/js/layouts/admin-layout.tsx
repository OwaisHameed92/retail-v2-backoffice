import { AdminSidebar } from '@/components/admin/admin-sidebar';
import { AdminTopbar } from '@/components/admin/admin-topbar';
import { LicenceKeysDialog } from '@/components/admin/licences/licence-keys-dialog';
import { Toaster } from '@/components/shared/toaster';
import { ShellFrame } from '@/components/shell/shell-frame';
import { TopbarBreadcrumbs, type TopbarCrumb } from '@/components/shell/topbar';
import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface AdminLayoutProps {
    children: ReactNode;
    /** Trail shown above the page on detail pages ("Customers › Tenants › Khan Mini Mart"). */
    breadcrumbs?: TopbarCrumb[];
    /** `wide` drops the max width for dense tables; `narrow` centres forms at max-w-4xl. */
    width?: 'default' | 'wide' | 'narrow';
}

/**
 * Layout for the super admin area (/admin), design system v2: dark top bar + dark sidebar as one frame (sheet on
 * phones), the page on the light canvas in a centred container. Breadcrumbs show above the page only when a
 * page passes a trail deeper than its section. Mounts the one toaster and the one "Licence key created" dialog.
 */
export default function AdminLayout({ children, breadcrumbs, width = 'default' }: AdminLayoutProps) {
    return (
        <ShellFrame storageKey="admin-sidebar" header={<AdminTopbar />} sidebar={<AdminSidebar />}>
            <div
                className={cn(
                    'mx-auto flex w-full flex-1 flex-col gap-4 px-4 py-5 sm:px-6 lg:px-8 lg:py-6',
                    width === 'default' && 'max-w-[90rem]',
                    width === 'narrow' && 'max-w-4xl',
                    width === 'wide' && 'max-w-[100rem]',
                )}
            >
                {breadcrumbs && breadcrumbs.length > 1 && <TopbarBreadcrumbs items={breadcrumbs} className="-mb-1" />}
                {children}
            </div>
            <Toaster />
            <LicenceKeysDialog />
        </ShellFrame>
    );
}
