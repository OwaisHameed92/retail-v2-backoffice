import { currentAdminNavItem } from '@/components/admin/admin-nav';
import { AdminSearch } from '@/components/admin/admin-search';
import { AdminUserMenu } from '@/components/admin/admin-user-menu';
import { type AdminSharedData } from '@/components/admin/types';
import { HelpMenu, NotificationsMenu, Topbar, TopbarBreadcrumbs, type TopbarCrumb } from '@/components/shell/topbar';
import { usePage } from '@inertiajs/react';

/** Default trail from the sidebar: "Customers › Tenants". Pages can pass a longer one to AdminLayout. */
function defaultCrumbs(): TopbarCrumb[] {
    const item = currentAdminNavItem();
    if (!item) {
        return [];
    }

    return [...(item.group ? [{ title: item.group }] : []), { title: item.title, href: item.route ? route(item.route) : undefined }];
}

/** Admin top bar: breadcrumbs, global search (⌘K), help, notifications and the account menu. */
export function AdminTopbar({ breadcrumbs }: { breadcrumbs?: TopbarCrumb[] }) {
    const { admin } = usePage<AdminSharedData>().props;

    return (
        <Topbar
            breadcrumbs={<TopbarBreadcrumbs items={breadcrumbs ?? defaultCrumbs()} />}
            search={
                <div role="search" className="flex w-full max-w-md min-w-0">
                    <AdminSearch />
                </div>
            }
            actions={
                <>
                    <HelpMenu />
                    <NotificationsMenu />
                    {admin && <AdminUserMenu admin={admin} />}
                </>
            }
        />
    );
}
