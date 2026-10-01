import { AdminSearch } from '@/components/admin/admin-search';
import { AdminUserMenu } from '@/components/admin/admin-user-menu';
import { type AdminSharedData } from '@/components/admin/types';
import { HelpMenu, NotificationsMenu, Topbar, TopbarDivider } from '@/components/shell/topbar';
import { usePage } from '@inertiajs/react';

/** Admin top bar (light, mint wash): menu button, global search (⌘K), notifications, help and the account menu. */
export function AdminTopbar() {
    const { admin } = usePage<AdminSharedData>().props;

    return (
        <Topbar
            home={route('admin.dashboard')}
            search={
                <div role="search" className="flex w-full max-w-2xl min-w-0 justify-end sm:justify-start">
                    <AdminSearch />
                </div>
            }
            actions={
                <>
                    <NotificationsMenu />
                    <HelpMenu />
                    <TopbarDivider />
                    {admin && <AdminUserMenu admin={admin} />}
                </>
            }
        />
    );
}
