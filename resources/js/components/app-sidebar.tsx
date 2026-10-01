import { tenantNav } from '@/components/app-nav';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { ShellSidebar } from '@/components/shell/sidebar-brand';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/** Business name at the top of the sidebar (a tile with initials when collapsed). */
function WorkspaceHeader({ name }: { name: string }) {
    return (
        <div className="px-3 pt-1 group-data-[collapsible=icon]:px-2">
            <div className="border-sidebar-border bg-subtle flex items-center gap-2.5 rounded-lg border px-2.5 py-2 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:border-0 group-data-[collapsible=icon]:bg-transparent group-data-[collapsible=icon]:p-0">
                <InitialsAvatar name={name} shape="square" size="sm" className="bg-sidebar-active text-sidebar-active-foreground" />
                <div className="min-w-0 leading-tight group-data-[collapsible=icon]:hidden">
                    <p className="text-sidebar-accent-foreground truncate text-sm font-semibold">{name}</p>
                    <p className="text-sidebar-muted text-[11px]">Business</p>
                </div>
            </div>
        </div>
    );
}

/** Business (tenant) portal sidebar: the same white sidebar as admin, its own navigation. */
export function AppSidebar() {
    const { company, abilities } = usePage<SharedData>().props;
    const path = usePage().url.split('?')[0];
    const { groups, pinned } = tenantNav(path, abilities ?? []);

    return (
        <ShellSidebar
            homeHref="/app"
            areaLabel="Business portal"
            header={company ? <WorkspaceHeader name={company.name} /> : undefined}
            groups={groups}
            pinned={pinned}
        />
    );
}
