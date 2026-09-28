import { AppBillingBanner } from '@/components/app-billing-banner';
import { AppImpersonationBanner } from '@/components/app-impersonation-banner';
import { AppSidebar } from '@/components/app-sidebar';
import { AppFilterBar, AppSidebarHeader } from '@/components/app-sidebar-header';
import { Toaster } from '@/components/shared/toaster';
import { ShellFrame } from '@/components/shell/shell-frame';
import { TopbarBreadcrumbs } from '@/components/shell/topbar';
import { type BreadcrumbItem } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * Business (tenant) portal shell, design system v2: the same dark top bar + sidebar frame as admin with its own
 * navigation; the "Viewing as" banner sits above the top bar while impersonating. The page renders in a centred
 * container; breadcrumbs and the branch/date filters sit at the top of the canvas.
 */
export default function AppSidebarLayout({ children, breadcrumbs = [] }: { children: React.ReactNode; breadcrumbs?: BreadcrumbItem[] }) {
    const { impersonation } = usePage<{ impersonation?: unknown }>().props;

    return (
        <ShellFrame
            storageKey="sidebar"
            banner={impersonation ? <AppImpersonationBanner /> : undefined}
            bannerHeight="2.5rem"
            header={<AppSidebarHeader />}
            sidebar={<AppSidebar />}
        >
            <div className="mx-auto flex w-full max-w-[90rem] flex-1 flex-col gap-4 px-4 py-5 sm:px-6 lg:px-8 lg:py-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <TopbarBreadcrumbs items={breadcrumbs.length > 1 ? breadcrumbs : []} />
                    <div className="ml-auto">
                        <AppFilterBar />
                    </div>
                </div>
                <AppBillingBanner />
                {children}
            </div>
            <Toaster />
        </ShellFrame>
    );
}
