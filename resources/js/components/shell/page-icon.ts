import { currentAdminNavItem } from '@/components/admin/admin-nav';
import { currentTenantNavItem, startsWithPath } from '@/components/app-nav';
import { usePage } from '@inertiajs/react';
import { LayoutDashboard, LayoutGrid, UserRound, type LucideIcon } from 'lucide-react';

/**
 * The icon for a page: the icon of its sidebar nav item (admin by route name, tenant by URL), so the page header
 * always matches the sidebar. Account settings use the profile icon; anything unmatched gets the area dashboard icon.
 */
export function pageIconFor(path: string): LucideIcon {
    if (startsWithPath(path, '/admin')) {
        return currentAdminNavItem()?.icon ?? LayoutDashboard;
    }
    if (startsWithPath(path, '/settings')) {
        return UserRound;
    }

    return currentTenantNavItem(path)?.icon ?? LayoutGrid;
}

/** `pageIconFor` the current Inertia page. */
export function usePageIcon(): LucideIcon {
    return pageIconFor(usePage().url.split('?')[0]);
}
