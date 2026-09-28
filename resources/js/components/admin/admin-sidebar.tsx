import { adminNavGroups, adminNavItems, adminPinnedGroups, isAdminNavItemActive, type AdminNavItem } from '@/components/admin/admin-nav';
import { type AdminSharedData } from '@/components/admin/types';
import { ShellSidebar } from '@/components/shell/sidebar-brand';
import { type ShellNavGroup } from '@/components/shell/sidebar-nav';
import { usePage } from '@inertiajs/react';

/** Admin sidebar: the nav groups the signed-in admin's abilities allow, Settings pinned at the bottom. */
export function AdminSidebar() {
    const { admin } = usePage<AdminSharedData>().props;
    const abilities = admin?.abilities ?? [];
    const counts = admin?.navCounts ?? {};
    const items = adminNavItems.filter((item) => !item.ability || abilities.includes(item.ability));

    const toGroup = (label: (typeof adminNavGroups)[number]): ShellNavGroup => ({
        label: label || undefined,
        items: items
            .filter((item: AdminNavItem) => (item.group ?? '') === label)
            .map((item) => ({
                title: item.title,
                icon: item.icon,
                href: item.route ? route(item.route) : undefined,
                active: isAdminNavItemActive(item),
                soon: !item.route,
                count: item.countKey ? counts[item.countKey] : undefined,
            })),
    });

    const isPinned = (label: string) => (adminPinnedGroups as readonly string[]).includes(label);

    return (
        <ShellSidebar
            groups={adminNavGroups.filter((label) => !isPinned(label)).map(toGroup)}
            pinned={adminNavGroups.filter((label) => isPinned(label)).map(toGroup)}
        />
    );
}
