import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /** Not built yet: shown muted with a "Soon" badge and not linked. */
    soon?: boolean;
}

export type CompanyStatus = 'trial' | 'active' | 'overdue' | 'suspended' | 'cancelled';

export type CompanyRole = 'owner' | 'manager' | 'accountant' | 'staff';

export type Ability =
    | 'dashboard.view'
    | 'sales.view'
    | 'catalogue.view'
    | 'catalogue.manage'
    | 'prices.manage'
    | 'promotions.manage'
    | 'stock.view'
    | 'stock.manage'
    | 'customers.view'
    | 'customers.manage'
    | 'reports.view'
    | 'settings.manage'
    | 'users.manage'
    | 'billing.view'
    | 'billing.manage'
    | 'sync.manage'
    | 'staff.manage'
    | 'suppliers.manage'
    | 'cash.view'
    | 'staff.view'
    | 'purchasing.view'
    | 'purchasing.manage'
    | 'transfers.view'
    | 'accounts.view'
    | 'news.view'
    | 'news.manage';

/** The company the user is working in (shared by HandleInertiaRequests). */
export interface CurrentCompany {
    id: string;
    name: string;
    status: CompanyStatus;
}

/** A company the user can switch to. */
export interface CompanyOption {
    id: string;
    name: string;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    company: CurrentCompany | null;
    companies: CompanyOption[];
    companyRole: CompanyRole | null;
    abilities: Ability[];
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
