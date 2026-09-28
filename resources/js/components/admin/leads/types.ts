import { type OnboardingBillingOptions } from '@/components/admin/billing/upfront-payment-fields';
import { type LicenceOptions, type PlanDefaults, type PlanOption } from '@/components/admin/licences/types';
import { type Nation, type Option } from '@/components/admin/tenants/types';
import { type Paginated } from '@/components/shared/data-table';

export type { Option };

/** App\Domain\Leads\Enums\LeadStatus. `approved` is never set by the actions (see the enum). */
export type LeadStatus = 'new' | 'contacted' | 'approved' | 'rejected' | 'converted';

export type LeadSource = 'website' | 'phone' | 'referral' | 'walkIn' | 'other';

export type BusinessType = 'convenience' | 'offLicence' | 'newsagent' | 'grocery' | 'forecourt' | 'other';

export interface AdminRef {
    id: string;
    name: string;
}

/** LeadData::listRow() */
export interface LeadRow {
    id: string;
    businessName: string;
    contactName: string;
    email: string | null;
    phone: string | null;
    town: string | null;
    postcode: string | null;
    shopsCount: number;
    tillsCount: number;
    businessType: BusinessType;
    source: LeadSource;
    status: LeadStatus;
    assignedAdmin: AdminRef | null;
    followUpAt: string | null;
    createdAt: string | null;
    archived: boolean;
}

/** LeadData::detail() */
export interface LeadDetail extends LeadRow {
    currentSystem: string | null;
    message: string | null;
    consentMarketing: boolean;
    utm: Record<string, string> | null;
    ip: string | null;
    contactedAt: string | null;
    lastContactedAt: string | null;
    rejectionReason: string | null;
    rejectedAt: string | null;
    convertedAt: string | null;
    convertedBy: AdminRef | null;
    company: { id: string; name: string; status: string; deleted: boolean } | null;
    updatedAt: string | null;
}

export type LeadNoteKind = 'note' | 'created' | 'statusChanged' | 'assigned' | 'followUp' | 'updated' | 'duplicate';

/** LeadData::note() */
export interface LeadNoteRow {
    id: string;
    kind: LeadNoteKind;
    system: boolean;
    body: string;
    meta: Record<string, unknown> | null;
    author: AdminRef | null;
    createdAt: string | null;
}

/** DuplicateMatch::toArray() */
export interface DuplicateMatch {
    type: 'lead' | 'tenant';
    id: string;
    name: string;
    status: string;
    matchedOn: ('email' | 'phone')[];
    createdAt: string | null;
}

/** LeadData::options() */
export interface LeadOptions {
    sources: Option<LeadSource>[];
    businessTypes: Option<BusinessType>[];
    admins: Option[];
    maxShops: number;
    maxTills: number;
}

/** LeadStatsData::toArray() — also used by the admin dashboard (module 1.9). */
export interface LeadStats {
    newThisWeek: number;
    awaitingContact: number;
    followUpsDue: number;
    overdueFollowUps: number;
    conversionRate: number | null;
    receivedInWindow: number;
    convertedInWindow: number;
    windowDays: number;
}

export type FollowUpFilter = 'due' | 'overdue' | 'week' | 'none';

export interface LeadFilterValues {
    status: LeadStatus | 'open' | 'archived' | null;
    source: LeadSource | null;
    assigned: string | null;
    followUp: FollowUpFilter | null;
}

export interface BoardColumn {
    status: LeadStatus;
    label: string;
    total: number;
    leads: LeadRow[];
}

export interface LeadIndexProps {
    view: 'list' | 'board';
    leads: Paginated<LeadRow> | null;
    board: BoardColumn[] | null;
    filters: LeadFilterValues;
    search: string | null;
    counts: Partial<Record<LeadStatus | 'open' | 'archived', number>>;
    mine: number;
    stats: LeadStats;
    statuses: Option<LeadStatus>[];
    options: LeadOptions;
    can: { create: boolean };
}

export type TrialShopInput = {
    name: string;
    code: string;
    tills: number;
    nation: Nation;
    /** Module 1.11: tills allowed (the keys' maxRegisters), at least `tills`. */
    tills_allowed?: number;
};

export interface ApprovalData {
    suggestion: { shops: TrialShopInput[]; planId: string | null };
    plans: PlanOption[];
    defaultPlanId: string | null;
    trialDays: number;
    plansTrialDays: Record<string, number>;
    ownerHasLogin: boolean;
    nations: Option<Nation>[];
    maxTillsPerShop: number;
    maxShops: number;
    /** Module 1.11: the licence form and each plan's defaults. */
    licenceOptions: LicenceOptions;
    planDefaults: PlanDefaults;
    /** Module 1.13: the upfront payment and the Direct Debit deadline. */
    billing: OnboardingBillingOptions;
}

export interface LeadShowProps {
    lead: LeadDetail;
    notes: LeadNoteRow[];
    duplicates: DuplicateMatch[];
    approval: ApprovalData | null;
    options: LeadOptions;
    defaultFollowUpTime: string;
    can: { update: boolean; approve: boolean; viewTenant: boolean };
}

/** The add/edit form (LeadRequest). */
export type LeadFormData = {
    business_name: string;
    contact_name: string;
    email: string;
    phone: string;
    town: string;
    postcode: string;
    shops_count: number;
    tills_count: number;
    business_type: BusinessType;
    current_system: string;
    message: string;
    source: LeadSource;
    consent_marketing: boolean;
    assigned_admin_id?: string;
    follow_up_date?: string;
    follow_up_time?: string;
};
