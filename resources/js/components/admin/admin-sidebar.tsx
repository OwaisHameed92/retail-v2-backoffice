import { adminNavGroups, adminNavItems } from '@/components/admin/admin-nav';
import { AdminSidebarUser } from '@/components/admin/admin-user-menu';
import { type AdminSharedData } from '@/components/admin/types';
import { SidebarBrand } from '@/components/shell/sidebar-brand';
import { SidebarNav, type ShellNavGroup } from '@/components/shell/sidebar-nav';
import { Sidebar, SidebarContent, SidebarFooter, SidebarRail } from '@/components/ui/sidebar';
import { usePage } from '@inertiajs/react';

export function AdminSidebar() {
    const { admin } = usePage<AdminSharedData>().props;
    const abilities = admin?.abilities ?? [];
    const items = adminNavItems.filter((item) => !item.ability || abilities.includes(item.ability));

    const groups: ShellNavGroup[] = adminNavGroups.map((label) => ({
        label: label || undefined,
        items: items
            .filter((item) => (item.group ?? '') === label)
            .map((item) => ({
                title: item.title,
                icon: item.icon,
                href: item.route ? route(item.route) : undefined,
                active: item.route ? route().current(item.activePattern ?? item.route) : false,
                soon: !item.route,
            })),
    }));

    return (
        <Sidebar collapsible="icon">
            <SidebarBrand href={route('admin.dashboard')} subtitle="Admin console" tag="Staff" />

            <SidebarContent className="gap-0 pb-2">
                <SidebarNav groups={groups} />
            </SidebarContent>

            {admin && (
                <SidebarFooter className="border-sidebar-border border-t p-2">
                    <AdminSidebarUser admin={admin} />
                </SidebarFooter>
            )}
            <SidebarRail />
        </Sidebar>
    );
}
