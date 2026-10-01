import { InitialsAvatar } from '@/components/shared/entity-cell';
import { ShellSidebar } from '@/components/shell/sidebar-brand';
import { type ShellNavGroup, type ShellNavItem } from '@/components/shell/sidebar-nav';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Banknote,
    BarChart3,
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
    Newspaper,
    Package,
    PackageOpen,
    Pill,
    PoundSterling,
    Receipt,
    Settings,
    ShieldCheck,
    Store,
    Tag,
    Truck,
    UserCog,
    Users,
} from 'lucide-react';

interface TenantNavItem extends ShellNavItem {
    /** Ability (shared `abilities` prop) the user needs; items the user cannot open are hidden, never shown as "Soon". */
    ability?: string;
}

interface TenantNavGroup extends Omit<ShellNavGroup, 'items'> {
    items: TenantNavItem[];
}

const starts = (path: string, prefix: string) => path === prefix || path.startsWith(`${prefix}/`);

/**
 * Tenant portal navigation, grouped by job (module 7.1): Selling, Catalogue, Stock & purchasing, Money, Team and
 * Compliance scroll; Settings is pinned near the bottom. Rarely used groups start collapsed; a group holding the
 * current page is always open. Items need their ability; empty groups disappear.
 */
function tenantNav(path: string, abilities: string[]): { groups: ShellNavGroup[]; pinned: ShellNavGroup[] } {
    const staffTime = starts(path, '/app/staff/time');
    const all: TenantNavGroup[] = [
        {
            items: [
                { title: 'Dashboard', icon: LayoutGrid, href: '/app', active: path === '/app' },
                { title: 'Reports', icon: BarChart3, href: '/app/reports', active: starts(path, '/app/reports'), ability: 'reports.view' },
            ],
        },
        {
            label: 'Selling',
            collapsible: 'open',
            items: [
                { title: 'Sales', icon: Receipt, href: '/app/sales', active: starts(path, '/app/sales'), ability: 'sales.view' },
                { title: 'Customers', icon: Users, href: '/app/customers', active: starts(path, '/app/customers'), ability: 'customers.view' },
                { title: 'Promotions', icon: Tag, href: '/app/promotions', active: starts(path, '/app/promotions'), ability: 'catalogue.view' },
                { title: 'Newspapers', icon: Newspaper, href: '/app/news', active: starts(path, '/app/news'), ability: 'news.view' },
                { title: 'Pharmacy', icon: Pill, href: '/app/pharmacy', active: starts(path, '/app/pharmacy'), ability: 'pharmacy.view' },
                { title: 'Parcels', icon: PackageOpen, href: '/app/parcels', active: starts(path, '/app/parcels'), ability: 'parcels.view' },
            ],
        },
        {
            label: 'Catalogue',
            collapsible: 'open',
            items: [
                { title: 'Products', icon: Package, href: '/app/products', active: starts(path, '/app/products'), ability: 'catalogue.view' },
                { title: 'Prices', icon: PoundSterling, href: '/app/prices', active: starts(path, '/app/prices'), ability: 'catalogue.view' },
                { title: 'Suppliers', icon: Factory, href: '/app/suppliers', active: starts(path, '/app/suppliers'), ability: 'suppliers.manage' },
            ],
        },
        {
            label: 'Stock & purchasing',
            collapsible: 'open',
            items: [
                { title: 'Stock', icon: Boxes, href: '/app/stock', active: starts(path, '/app/stock'), ability: 'stock.view' },
                {
                    title: 'Purchasing',
                    icon: Truck,
                    href: '/app/purchasing/orders',
                    active: starts(path, '/app/purchasing'),
                    ability: 'purchasing.view',
                },
                {
                    title: 'Transfers',
                    icon: ArrowLeftRight,
                    href: '/app/transfers',
                    active: starts(path, '/app/transfers'),
                    ability: 'transfers.view',
                },
            ],
        },
        {
            label: 'Money',
            collapsible: 'open',
            items: [
                { title: 'Cash and Z', icon: Banknote, href: '/app/cash', active: starts(path, '/app/cash'), ability: 'cash.view' },
                { title: 'Accounts', icon: BookOpen, href: '/app/accounts', active: starts(path, '/app/accounts'), ability: 'accounts.view' },
            ],
        },
        {
            label: 'Team',
            collapsible: 'open',
            items: [
                { title: 'Staff', icon: UserCog, href: '/app/staff', active: starts(path, '/app/staff') && !staffTime, ability: 'staff.manage' },
                { title: 'Staff time', icon: Clock, href: '/app/staff/time', active: staffTime, ability: 'staff.view' },
                { title: 'Portal users', icon: ShieldCheck, href: '/app/users', active: starts(path, '/app/users'), ability: 'users.manage' },
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
                    active: starts(path, '/app/compliance'),
                    ability: 'compliance.view',
                },
            ],
        },
        {
            label: 'Settings',
            collapsible: 'closed',
            items: [
                { title: 'Shops and tills', icon: Store, href: '/app/shops', active: starts(path, '/app/shops'), ability: 'shops.view' },
                { title: 'Till settings', icon: Settings, href: '/app/settings', active: starts(path, '/app/settings'), ability: 'settings.manage' },
                {
                    title: 'Payment types',
                    icon: ListChecks,
                    href: '/app/payment-types',
                    active: starts(path, '/app/payment-types') || starts(path, '/app/reasons'),
                    ability: 'settings.manage',
                },
                { title: 'Calendar', icon: CalendarDays, href: '/app/calendar', active: starts(path, '/app/calendar'), ability: 'calendar.manage' },
                {
                    title: 'Sync conflicts',
                    icon: GitCompareArrows,
                    href: '/app/sync/conflicts',
                    active: starts(path, '/app/sync'),
                    ability: 'sync.manage',
                },
                { title: 'My subscription', icon: CreditCard, href: '/app/billing', active: starts(path, '/app/billing'), ability: 'billing.view' },
            ],
        },
    ];

    const visible = all.map((group) => ({ ...group, items: group.items.filter((item) => !item.ability || abilities.includes(item.ability)) }));

    return {
        groups: visible.filter((group) => group.label !== 'Settings'),
        pinned: visible.filter((group) => group.label === 'Settings'),
    };
}

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
