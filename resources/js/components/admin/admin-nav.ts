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
}

/** Sidebar items, in order. Later modules add a `route` when their pages exist. */
export const adminNavItems: AdminNavItem[] = [
    { title: 'Dashboard', icon: LayoutDashboard, route: 'admin.dashboard', activePattern: 'admin.dashboard' },
    { title: 'Leads', icon: Inbox },
    { title: 'Tenants', icon: Building2, route: 'admin.tenants.index', activePattern: 'admin.tenants.*', ability: 'tenants.view' },
    { title: 'Licences', icon: KeyRound },
    { title: 'Plans', icon: Layers, route: 'admin.plans.index', activePattern: 'admin.plans.*', ability: 'billing.manage' },
    { title: 'Billing', icon: Receipt },
    { title: 'Till health', icon: Activity },
    { title: 'EPOS versions', icon: MonitorDown },
    { title: 'Emails', icon: Mail, route: 'admin.emails.index', activePattern: 'admin.emails.*', ability: 'licences.manage' },
    { title: 'Admin users', icon: UsersRound, route: 'admin.admins.index', activePattern: 'admin.admins.*', ability: 'admins.manage' },
    { title: 'Audit log', icon: ScrollText },
];
