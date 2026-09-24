import { AdminUserMenu } from '@/components/admin/admin-user-menu';
import { type AdminSharedData } from '@/components/admin/types';
import AppearanceToggleDropdown from '@/components/appearance-dropdown';
import { Input } from '@/components/ui/input';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';

export function AdminTopbar() {
    const { admin } = usePage<AdminSharedData>().props;

    return (
        <header className="border-sidebar-border/50 flex h-14 shrink-0 items-center gap-2 border-b px-4">
            <SidebarTrigger className="-ml-1" />

            {/* Global search arrives with the tenants and licences modules. */}
            <form role="search" className="relative max-w-md flex-1" onSubmit={(e) => e.preventDefault()}>
                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
                <Input type="search" aria-label="Search" placeholder="Search tenant, licence key, device ID" className="h-9 pl-8" />
            </form>

            <div className="ml-auto flex items-center gap-1">
                <AppearanceToggleDropdown className="hidden sm:block" />
                {admin && <AdminUserMenu admin={admin} />}
            </div>
        </header>
    );
}
