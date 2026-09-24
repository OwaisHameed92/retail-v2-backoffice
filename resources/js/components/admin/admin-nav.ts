import {
    type LucideIcon,
    Activity,
    Building2,
    Inbox,
    KeyRound,
    Layers,
    LayoutDashboard,
    Mail,
    MonitorDown,
    Receipt,
    ScrollText,
    UsersRound,
} from 'lucide-react';

export interface AdminNavItem {
    title: string;
    icon: LucideIcon;
    /** Ziggy route name. Items without a route are shown as "Soon". */
    route?: string;
    /** Route name prefix used to mark the item active. */
    activePattern?: string;
    /** Ability the signed-in admin needs to see the item. */
    ability?: string;
    /** Sidebar group label ("Customers", "Billing"…). Omit for the top, unlabelled group. */
    group?: AdminNavGroup;
}

/** Sidebar groups, in order. A new module adds its item to one of these (or a new group here). */
export const adminNavGroups = ['', 'Customers', 'Billing', 'Operations', 'Settings'] as const;
export type AdminNavGroup = Exclude<(typeof adminNavGroups)[number], ''>;

/** Sidebar items, in order within their group. Later modules add a `route` when their pages exist ("Soon" until then). */
export const adminNavItems: AdminNavItem[] = [
    { title: 'Dashboard', icon: LayoutDashboard, route: 'admin.dashboard', activePattern: 'admin.dashboard' },
    { title: 'Leads', icon: Inbox, route: 'admin.leads.index', activePattern: 'admin.leads.*', ability: 'tenants.view', group: 'Customers' },
    {
        title: 'Tenants',
        icon: Building2,
        route: 'admin.tenants.index',
        activePattern: 'admin.tenants.*',
        ability: 'tenants.view',
        group: 'Customers',
    },
    {
        title: 'Licences',
        icon: KeyRound,
        route: 'admin.licences.index',
        activePattern: 'admin.licences.*',
        ability: 'tenants.view',
        group: 'Customers',
    },
    { title: 'Plans', icon: Layers, route: 'admin.plans.index', activePattern: 'admin.plans.*', ability: 'billing.manage', group: 'Billing' },
    { title: 'Billing', icon: Receipt, route: 'admin.billing.index', activePattern: 'admin.billing.*', ability: 'tenants.view', group: 'Billing' },
    { title: 'Till health', icon: Activity, group: 'Operations' },
    { title: 'EPOS versions', icon: MonitorDown, group: 'Operations' },
    { title: 'Emails', icon: Mail, route: 'admin.emails.index', activePattern: 'admin.emails.*', ability: 'licences.manage', group: 'Operations' },
    {
        title: 'Admin users',
        icon: UsersRound,
        route: 'admin.admins.index',
        activePattern: 'admin.admins.*',
        ability: 'admins.manage',
        group: 'Settings',
    },
    { title: 'Audit log', icon: ScrollText, group: 'Settings' },
];

/** The nav item for the current route, used for default breadcrumbs ("Customers › Tenants"). */
export function currentAdminNavItem(): AdminNavItem | undefined {
    return adminNavItems.find((item) => item.route && route().current(item.activePattern ?? item.route));
}
