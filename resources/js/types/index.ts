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
    | 'news.manage'
    | 'compliance.view'
    | 'compliance.manage'
    | 'calendar.manage'
    | 'pharmacy.view'
    | 'parcels.view'
    | 'privacy.manage'
    | 'accounts.export'
    | 'ai.use'
    | 'labels.print';

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

/** The instance's country profile (Pakistan plan P0), shared by HandleInertiaRequests from config/country.php. */
export interface CountryProfile {
    code: 'GB' | 'PK';
    name: string;
    /** ISO 4217: "GBP", "PKR". */
    currency: string;
    /** "£", "Rs". */
    currencySymbol: string;
    /** "Rs 1,250" has a space; "£1,250.00" has none. */
    currencySymbolSpace: boolean;
    displayDecimals: number;
    /** "thousands" (1,234,567) or "lakh" (12,34,567). */
    grouping: 'thousands' | 'lakh';
    numberLocale: string;
    dateLocale: string;
    /** "Europe/London", "Asia/Karachi". */
    timezone: string;
    /** "VAT", "GST". */
    taxName: string;
    /** GB: vatNumber, companyNumber. PK: ntn, strn, companyNumber. */
    taxIds: Record<string, { label: string; example: string }>;
    address: { postcodeLabel: string; postcodeRequired: boolean; postcodeExample: string; cityRequired: boolean };
    phoneExample: string;
    billingCollection: 'gocardless' | 'manual';
    features: { vatReturn: boolean; fbr: boolean } & Record<string, boolean>;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    company: CurrentCompany | null;
    companies: CompanyOption[];
    companyRole: CompanyRole | null;
    abilities: Ability[];
    country: CountryProfile;
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
