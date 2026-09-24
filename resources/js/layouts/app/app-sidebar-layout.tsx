import { AppContent } from '@/components/app-content';
import { AppImpersonationBanner } from '@/components/app-impersonation-banner';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppFilterBar, AppSidebarHeader } from '@/components/app-sidebar-header';
import { Toaster } from '@/components/shared/toaster';
import { type BreadcrumbItem } from '@/types';
import { usePage } from '@inertiajs/react';
import { type CSSProperties } from 'react';

/**
 * Tenant portal shell: grouped sidebar, sticky top bar, and the page in a centred max-w-7xl container with
 * consistent padding and 24px between blocks. Pages render PageHeader + cards, never their own padding.
 */
export default function AppSidebarLayout({ children, breadcrumbs = [] }: { children: React.ReactNode; breadcrumbs?: BreadcrumbItem[] }) {
    const { impersonation } = usePage<{ impersonation?: unknown }>().props;
    // Sticky table headers sit under the top bar (and the "Viewing as" banner while impersonating).
    const headerHeight = impersonation ? '6rem' : '3.5rem';

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0" style={{ '--app-header-height': headerHeight } as CSSProperties}>
                <div className="sticky top-0 z-30">
                    <AppImpersonationBanner />
                    <AppSidebarHeader breadcrumbs={breadcrumbs} />
                </div>
                <AppFilterBar />
                <div className="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">{children}</div>
            </AppContent>
            <Toaster />
        </AppShell>
    );
}
