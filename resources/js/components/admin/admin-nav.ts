import {
    type LucideIcon,
    Activity,
    Building2,
    CloudUpload,
    Contact,
    FileText,
    Inbox,
    KeyRound,
    Layers,
    LayoutDashboard,
    LayoutTemplate,
    Mail,
    MonitorDown,
    MonitorSmartphone,
    Receipt,
    RefreshCw,
    ScrollText,
    Settings,
    UsersRound,
} from 'lucide-react';

export interface AdminNavItem {
    title: string;
    icon: LucideIcon;
    /** Ziggy route name. Items without a route are shown as "Soon". */
    route?: string;
    /** Route name pattern(s) used to mark the item active. */
    activePattern?: string | string[];
    /** Ability the signed-in admin needs to see the item. */
    ability?: string;
    /** Sidebar group label ("Customers", "Billing"…). Omit for the top, unlabelled Overview group. */
    group?: AdminNavGroup;
    /** Key into the optional `admin.navCounts` shared prop; shows a green count pill when > 0. */
    countKey?: string;
}

/** Sidebar groups, in order. "Settings" is pinned near the bottom of the sidebar. */
export const adminNavGroups = ['', 'Customers', 'Billing', 'Operations', 'Communications', 'Settings'] as const;
export type AdminNavGroup = Exclude<(typeof adminNavGroups)[number], ''>;
export const adminPinnedGroups: readonly AdminNavGroup[] = ['Settings'];

/** Sidebar items, in order within their group. Later modules add a `route` when their pages exist ("Soon" until then). */
export const adminNavItems: AdminNavItem[] = [
    { title: 'Dashboard', icon: LayoutDashboard, route: 'admin.dashboard', activePattern: ['admin.dashboard', 'admin.trading'] },
    { title: 'Customers', icon: Contact, group: 'Customers' },
    {
        title: 'Leads',
        icon: Inbox,
        route: 'admin.leads.index',
        activePattern: 'admin.leads.*',
        ability: 'tenants.view',
        group: 'Customers',
        countKey: 'leads',
    },
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
    {
        title: 'Billing',
        icon: Receipt,
        route: 'admin.billing.index',
        activePattern: ['admin.billing.index', 'admin.billing.payments.*', 'admin.billing.tenants.*'],
        ability: 'billing.manage',
        group: 'Billing',
    },
    {
        title: 'Invoices',
        icon: FileText,
        route: 'admin.billing.invoices.index',
        activePattern: 'admin.billing.invoices.*',
        ability: 'billing.manage',
        group: 'Billing',
    },
    { title: 'Plans', icon: Layers, route: 'admin.plans.index', activePattern: 'admin.plans.*', ability: 'billing.manage', group: 'Billing' },
    {
        title: 'Till health',
        icon: Activity,
        route: 'admin.till-health.index',
        activePattern: 'admin.till-health.*',
        ability: 'tenants.view',
        group: 'Operations',
    },
    {
        title: 'Cloud link',
        icon: CloudUpload,
        route: 'admin.cloud-link.index',
        activePattern: 'admin.cloud-link.*',
        ability: 'tenants.view',
        group: 'Operations',
    },
    { title: 'Devices', icon: MonitorSmartphone, group: 'Operations' },
    { title: 'Sync & jobs', icon: RefreshCw, group: 'Operations' },
    { title: 'EPOS versions', icon: MonitorDown, group: 'Operations' },
    {
        title: 'Emails',
        icon: Mail,
        route: 'admin.emails.index',
        activePattern: 'admin.emails.index',
        ability: 'licences.manage',
        group: 'Communications',
    },
    {
        title: 'Templates',
        icon: LayoutTemplate,
        route: 'admin.emails.templates',
        activePattern: 'admin.emails.templates*',
        ability: 'licences.manage',
        group: 'Communications',
    },
    {
        title: 'Admin users',
        icon: UsersRound,
        route: 'admin.admins.index',
        activePattern: 'admin.admins.*',
        ability: 'admins.manage',
        group: 'Settings',
    },
    { title: 'Audit log', icon: ScrollText, group: 'Settings' },
    { title: 'Settings', icon: Settings, group: 'Settings' },
];

/** True when the current route matches the item's active pattern(s). */
export function isAdminNavItemActive(item: AdminNavItem): boolean {
    if (!item.route) {
        return false;
    }
    const patterns = Array.isArray(item.activePattern) ? item.activePattern : [item.activePattern ?? item.route];

    return patterns.some((pattern) => route().current(pattern));
}

/** The nav item for the current route, used for default breadcrumbs ("Customers › Tenants"). */
export function currentAdminNavItem(): AdminNavItem | undefined {
    return adminNavItems.find((item) => isAdminNavItemActive(item));
}
