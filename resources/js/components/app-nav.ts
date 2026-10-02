import { type ShellNavGroup, type ShellNavItem } from '@/components/shell/sidebar-nav';
import {
    ArrowLeftRight,
    Banknote,
    BarChart3,
    Barcode,
    BookOpen,
    Boxes,
    CalendarDays,
    ClipboardCheck,
    Clock,
    CreditCard,
    Factory,
    GitCompareArrows,
    LayoutGrid,
    ListChecks,
    LockKeyhole,
    Newspaper,
    Package,
    PackageOpen,
    Pill,
    PoundSterling,
    Receipt,
    ScrollText,
    Settings,
    ShieldAlert,
    ShieldCheck,
    ShoppingCart,
    Store,
    Tag,
    Truck,
    UserCog,
    Users,
} from 'lucide-react';

export interface TenantNavItem extends ShellNavItem {
    /** Ability (shared `abilities` prop) the user needs; items the user cannot open are hidden, never shown as "Soon". */
    ability?: string;
}

export interface TenantNavGroup extends Omit<ShellNavGroup, 'items'> {
    items: TenantNavItem[];
}

export const startsWithPath = (path: string, prefix: string) => path === prefix || path.startsWith(`${prefix}/`);

/**
 * Tenant portal navigation, grouped by job (module 7.1): Selling, Catalogue, Stock & purchasing, Money, Team and
 * Compliance scroll; Settings is pinned near the bottom. Rarely used groups start collapsed; a group holding the
 * current page is always open. All items, before the ability filter (also the source of page header icons).
 */
export function tenantNavGroups(path: string): TenantNavGroup[] {
    const staffTime = startsWithPath(path, '/app/staff/time');

    return [
        {
            items: [
                { title: 'Dashboard', icon: LayoutGrid, href: '/app', active: path === '/app' },
                { title: 'Reports', icon: BarChart3, href: '/app/reports', active: startsWithPath(path, '/app/reports'), ability: 'reports.view' },
                {
                    title: 'Unusual activity',
                    icon: ShieldAlert,
                    href: '/app/anomalies',
                    active: startsWithPath(path, '/app/anomalies'),
                    ability: 'reports.view',
                },
            ],
        },
        {
            label: 'Selling',
            collapsible: 'open',
            items: [
                { title: 'Sales', icon: Receipt, href: '/app/sales', active: startsWithPath(path, '/app/sales'), ability: 'sales.view' },
                {
                    title: 'Customers',
                    icon: Users,
                    href: '/app/customers',
                    active: startsWithPath(path, '/app/customers'),
                    ability: 'customers.view',
                },
                {
                    title: 'Promotions',
                    icon: Tag,
                    href: '/app/promotions',
                    active: startsWithPath(path, '/app/promotions'),
                    ability: 'catalogue.view',
                },
                { title: 'Newspapers', icon: Newspaper, href: '/app/news', active: startsWithPath(path, '/app/news'), ability: 'news.view' },
                { title: 'Pharmacy', icon: Pill, href: '/app/pharmacy', active: startsWithPath(path, '/app/pharmacy'), ability: 'pharmacy.view' },
                { title: 'Parcels', icon: PackageOpen, href: '/app/parcels', active: startsWithPath(path, '/app/parcels'), ability: 'parcels.view' },
            ],
        },
        {
            label: 'Catalogue',
            collapsible: 'open',
            items: [
                { title: 'Products', icon: Package, href: '/app/products', active: startsWithPath(path, '/app/products'), ability: 'catalogue.view' },
                { title: 'Prices', icon: PoundSterling, href: '/app/prices', active: startsWithPath(path, '/app/prices'), ability: 'catalogue.view' },
                { title: 'Shelf labels', icon: Barcode, href: '/app/labels', active: startsWithPath(path, '/app/labels'), ability: 'labels.print' },
                {
                    title: 'Suppliers',
                    icon: Factory,
                    href: '/app/suppliers',
                    active: startsWithPath(path, '/app/suppliers'),
                    ability: 'suppliers.manage',
                },
            ],
        },
        {
            label: 'Stock & purchasing',
            collapsible: 'open',
            items: [
                { title: 'Stock', icon: Boxes, href: '/app/stock', active: startsWithPath(path, '/app/stock'), ability: 'stock.view' },
                {
                    title: 'Purchasing',
                    icon: Truck,
                    href: '/app/purchasing/orders',
                    active: startsWithPath(path, '/app/purchasing') && !startsWithPath(path, '/app/purchasing/suggestions'),
                    ability: 'purchasing.view',
                },
                {
                    title: 'Reorder suggestions',
                    icon: ShoppingCart,
                    href: '/app/purchasing/suggestions',
                    active: startsWithPath(path, '/app/purchasing/suggestions'),
                    ability: 'purchasing.view',
                },
                {
                    title: 'Transfers',
                    icon: ArrowLeftRight,
                    href: '/app/transfers',
                    active: startsWithPath(path, '/app/transfers'),
                    ability: 'transfers.view',
                },
            ],
        },
        {
            label: 'Money',
            collapsible: 'open',
            items: [
                { title: 'Cash and Z', icon: Banknote, href: '/app/cash', active: startsWithPath(path, '/app/cash'), ability: 'cash.view' },
                { title: 'Accounts', icon: BookOpen, href: '/app/accounts', active: startsWithPath(path, '/app/accounts'), ability: 'accounts.view' },
            ],
        },
        {
            label: 'Team',
            collapsible: 'open',
            items: [
                {
                    title: 'Staff',
                    icon: UserCog,
                    href: '/app/staff',
                    active: startsWithPath(path, '/app/staff') && !staffTime,
                    ability: 'staff.manage',
                },
                { title: 'Staff time', icon: Clock, href: '/app/staff/time', active: staffTime, ability: 'staff.view' },
                { title: 'Portal users', icon: ShieldCheck, href: '/app/users', active: startsWithPath(path, '/app/users'), ability: 'users.manage' },
            ],
        },
        {
            label: 'Compliance',
            collapsible: 'closed',
            items: [
                {
                    title: 'Compliance',
                    icon: ClipboardCheck,
                    href: '/app/compliance',
                    active: startsWithPath(path, '/app/compliance'),
                    ability: 'compliance.view',
                },
            ],
        },
        {
            label: 'Settings',
            collapsible: 'closed',
            items: [
                { title: 'Shops and tills', icon: Store, href: '/app/shops', active: startsWithPath(path, '/app/shops'), ability: 'shops.view' },
                {
                    title: 'Till settings',
                    icon: Settings,
                    href: '/app/settings',
                    active: startsWithPath(path, '/app/settings'),
                    ability: 'settings.manage',
                },
                {
                    title: 'Payment types',
                    icon: ListChecks,
                    href: '/app/payment-types',
                    active: startsWithPath(path, '/app/payment-types') || startsWithPath(path, '/app/reasons'),
                    ability: 'settings.manage',
                },
                {
                    title: 'Calendar',
                    icon: CalendarDays,
                    href: '/app/calendar',
                    active: startsWithPath(path, '/app/calendar'),
                    ability: 'calendar.manage',
                },
                {
                    title: 'Sync conflicts',
                    icon: GitCompareArrows,
                    href: '/app/sync/conflicts',
                    active: startsWithPath(path, '/app/sync'),
                    ability: 'sync.manage',
                },
                {
                    title: 'My subscription',
                    icon: CreditCard,
                    href: '/app/billing',
                    active: startsWithPath(path, '/app/billing'),
                    ability: 'billing.view',
                },
                {
                    title: 'Activity',
                    icon: ScrollText,
                    href: '/app/activity',
                    active: startsWithPath(path, '/app/activity'),
                    ability: 'audit.view',
                },
                {
                    title: 'Privacy',
                    icon: LockKeyhole,
                    href: '/app/privacy',
                    active: startsWithPath(path, '/app/privacy'),
                    ability: 'privacy.manage',
                },
            ],
        },
    ];
}

/** Tenant navigation for the signed-in user: items need their ability, Settings is pinned, empty groups vanish. */
export function tenantNav(path: string, abilities: string[]): { groups: ShellNavGroup[]; pinned: ShellNavGroup[] } {
    const visible = tenantNavGroups(path).map((group) => ({
        ...group,
        items: group.items.filter((item) => !item.ability || abilities.includes(item.ability)),
    }));

    return {
        groups: visible.filter((group) => group.label !== 'Settings'),
        pinned: visible.filter((group) => group.label === 'Settings'),
    };
}

/** The tenant nav item for a path (ignoring abilities); its icon is the page header icon. */
export function currentTenantNavItem(path: string): TenantNavItem | undefined {
    return tenantNavGroups(path)
        .flatMap((group) => group.items)
        .find((item) => item.active);
}
