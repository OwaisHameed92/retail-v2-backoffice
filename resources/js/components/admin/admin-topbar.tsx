import { AdminSearch } from '@/components/admin/admin-search';
import { AdminUserMenu } from '@/components/admin/admin-user-menu';
import { type AdminSharedData } from '@/components/admin/types';
import { HelpMenu, NotificationsMenu, Topbar, TopbarBrand } from '@/components/shell/topbar';
import { usePage } from '@inertiajs/react';

/** Admin top bar (dark chrome): logo, global search (⌘K), help, notifications and the account menu. */
export function AdminTopbar() {
    const { admin } = usePage<AdminSharedData>().props;

    return (
        <Topbar
            brand={<TopbarBrand href={route('admin.dashboard')} />}
            search={
                <div role="search" className="flex w-full max-w-xl min-w-0 justify-end sm:justify-center">
                    <AdminSearch />
                </div>
            }
            actions={
                <>
                    <NotificationsMenu />
                    <HelpMenu />
                    {admin && <AdminUserMenu admin={admin} />}
                </>
            }
        />
    );
}
