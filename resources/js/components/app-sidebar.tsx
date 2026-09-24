import { NavUser } from '@/components/nav-user';
import { SidebarBrand } from '@/components/shell/sidebar-brand';
import { SidebarNav, type ShellNavGroup } from '@/components/shell/sidebar-nav';
import { Sidebar, SidebarContent, SidebarFooter, SidebarRail } from '@/components/ui/sidebar';
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

export function AppSidebar() {
    const { company } = usePage<SharedData>().props;
    const path = usePage().url.split('?')[0];

    return (
        <Sidebar collapsible="icon">
            <SidebarBrand href="/app" subtitle={company?.name} />

            <SidebarContent className="gap-0 pb-2">
                <SidebarNav groups={tenantNav(path)} />
            </SidebarContent>

            <SidebarFooter className="border-sidebar-border border-t p-2">
                <NavUser />
            </SidebarFooter>
            <SidebarRail />
        </Sidebar>
    );
}
