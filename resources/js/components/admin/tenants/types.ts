import { type TenantBillingData } from '@/components/admin/billing/types';
import { type BranchLicence, type BranchLimits, type LicenceOptions, type PlanOption, type TenantLicensing } from '@/components/admin/licences/types';
import { type Paginated } from '@/components/shared/data-table';
import { type CompanyRole, type CompanyStatus } from '@/types';

export interface Option<T extends string = string> {
    value: T;
    label: string;
}

/** Row of the tenant list (TenantData::listRow). */
export interface TenantListRow {
    id: string;
    name: string;
    legalName: string | null;
    status: CompanyStatus;
    branchesCount: number;
    registersCount: number;
    ownerEmail: string | null;
    createdAt: string | null;
}

/** TenantData::company. */
export interface Tenant {
    id: string;
    name: string;
    legalName: string | null;
    vatNumber: string | null;
    companyNumber: string | null;
    address: string | null;
    phone: string | null;
    email: string | null;
    contactName: string | null;
    /** Module 1.11: the key's shop details. */
    businessType: string | null;
    businessTypeLabel: string | null;
    town: string | null;
    postcode: string | null;
    ownerName: string | null;
    receiptFooter: string | null;
    notes: string | null;
    status: CompanyStatus;
    trialEndsAt: string | null;
    activatedAt: string | null;
    suspendedAt: string | null;
    suspensionReason: string | null;
    cancelledAt: string | null;
    cancellationReason: string | null;
    createdAt: string | null;
}

export type Nation = 'england' | 'scotland' | 'wales' | 'northernIreland';

export interface TenantRegister {
    id: string;
    code: string;
    name: string;
    isMainTill: boolean;
    isActive: boolean;
    createdAt: string | null;
}

export interface TenantBranch {
    id: string;
    code: string;
    name: string;
    address: string | null;
    phone: string | null;
    vatNumber: string | null;
    town: string | null;
    postcode: string | null;
    receiptFooter: string | null;
    nation: Nation;
    nationLabel: string;
    licensedHoursJson: string | null;
    isDrsReturnPoint: boolean;
    areaM2: string | null;
    isActive: boolean;
    createdAt: string | null;
    registers: TenantRegister[];
    /** Module 1.11: licence settings with in use / allowed. */
    licence: BranchLicence;
}

export interface TenantMember {
    id: number;
    name: string;
    email: string;
    role: CompanyRole;
    roleLabel: string;
    isActive: boolean;
    isOwner: boolean;
    joinedAt: string | null;
}

export interface TenantActivityRow {
    id: string;
    action: string;
    description: string;
    actorKind: 'admin' | 'user' | 'system' | 'other';
    actorName: string;
    createdAt: string | null;
}

export interface TenantStats {
    branches: number;
    branchesInactive: number;
    tills: number;
    users: number;
}

export interface TenantShowProps {
    tenant: Tenant;
    stats: TenantStats;
    branches: TenantBranch[];
    members: TenantMember[];
    activity: Paginated<TenantActivityRow>;
    nations: Option<Nation>[];
    roles: Option<CompanyRole>[];
    maxTills: number;
    /** Module 1.3: licences of this tenant and of each till. */
    licensing: TenantLicensing;
    plans: PlanOption[];
    /** Module 1.8: billing settings, balance, invoices and payments. */
    billing: TenantBillingData;
    /** Module 1.11: the licence form. */
    branchLimits: BranchLimits;
    licenceOptions: LicenceOptions;
    can: { manage: boolean; impersonate: boolean; manageLicences: boolean };
}
