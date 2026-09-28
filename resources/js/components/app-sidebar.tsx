import { InitialsAvatar } from '@/components/shared/entity-cell';
import { ShellSidebar } from '@/components/shell/sidebar-brand';
import { type ShellNavGroup } from '@/components/shell/sidebar-nav';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { Banknote, BarChart3, BookOpen, Boxes, LayoutGrid, Package, Receipt, Settings, Tag, Truck, UserCog, Users } from 'lucide-react';

/**
 * Tenant portal navigation, grouped by job. Items without an `href` are modules not built yet ("Soon").
 * When a module ships, give its item an `href` and an `active` test.
 */
function tenantNav(path: string): ShellNavGroup[] {
    return [
        { items: [{ title: 'Dashboard', icon: LayoutGrid, href: '/app', active: path === '/app' }] },
        {
            label: 'Selling',
            items: [
                { title: 'Sales', icon: Receipt, soon: true },
                { title: 'Customers', icon: Users, soon: true },
                { title: 'Promotions', icon: Tag, soon: true },
            ],
        },
        {
            label: 'Catalogue',
            items: [
                { title: 'Products', icon: Package, soon: true },
                { title: 'Stock', icon: Boxes, soon: true },
                { title: 'Purchasing', icon: Truck, soon: true },
            ],
        },
        {
            label: 'Money',
            items: [
                { title: 'Cash and Z', icon: Banknote, soon: true },
                { title: 'Accounts', icon: BookOpen, soon: true },
                { title: 'Reports', icon: BarChart3, soon: true },
            ],
        },
        { label: 'Team', items: [{ title: 'Staff', icon: UserCog, soon: true }] },
        { label: 'Settings', items: [{ title: 'Business settings', icon: Settings, soon: true }] },
    ];
}

/** Business name at the top of the sidebar (a tile with initials when collapsed). */
function WorkspaceHeader({ name }: { name: string }) {
    return (
        <div className="px-3 pt-3 group-data-[collapsible=icon]:px-2">
            <div className="border-sidebar-border bg-sidebar-accent flex items-center gap-2.5 rounded-lg border px-2.5 py-2 group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:border-0 group-data-[collapsible=icon]:bg-transparent group-data-[collapsible=icon]:p-0">
                <InitialsAvatar name={name} shape="square" size="sm" className="bg-sidebar-active text-sidebar-accent-foreground" />
                <div className="min-w-0 leading-tight group-data-[collapsible=icon]:hidden">
                    <p className="text-sidebar-accent-foreground truncate text-sm font-semibold">{name}</p>
                    <p className="text-sidebar-muted text-[11px]">Business</p>
                </div>
            </div>
        </div>
    );
}

/** Business (tenant) portal sidebar: same dark frame as admin, its own navigation. */
export function AppSidebar() {
    const { company } = usePage<SharedData>().props;
    const path = usePage().url.split('?')[0];
    const groups = tenantNav(path);

    return (
        <ShellSidebar
            header={company ? <WorkspaceHeader name={company.name} /> : undefined}
            groups={groups.filter((group) => group.label !== 'Settings')}
            pinned={groups.filter((group) => group.label === 'Settings')}
        />
    );
}
