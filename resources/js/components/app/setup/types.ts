import { type Paginated } from '@/components/shared/data-table';

/** Module 4.5 props (App\Domain\Setup\Queries, App\Domain\Staff\Queries\StaffScreens). */
export interface Option {
    value: string;
    label: string;
}

export interface SupplierRow {
    id: string;
    name: string;
    code: string;
    contactName: string | null;
    phone: string | null;
    email: string | null;
    town: string | null;
    isActive: boolean;
    terms: string | null;
    orderMethod: string | null;
    deliveryDays: string | null;
    updatedAt: string | null;
}

export interface SupplierIndexProps {
    suppliers: Paginated<SupplierRow>;
    filters: { status: 'all' | 'active' | 'inactive' };
    counts: { all: number; active: number; inactive: number };
    canEdit: boolean;
}

export type SupplierFormData = {
    name: string;
    code: string;
    is_active: boolean;
    contact_name: string;
    phone: string;
    email: string;
    address_line1: string;
    address_line2: string;
    town: string;
    postcode: string;
    account_number: string;
    vat_number: string;
    terms_kind: string;
    payment_terms_days: string;
    default_lead_days: string;
    minimum_order_value: string;
    order_method: string;
    delivery_days: string[];
    notes: string;
};

export interface SupplierFormProps {
    supplier: (SupplierFormData & { id: string; updatedAt: string | null }) | null;
    options: { termsKinds: Option[]; orderMethods: Option[]; days: string[] };
    canEdit: boolean;
}

export const TENDER_FLAGS = [
    'is_cash',
    'is_card',
    'is_voucher',
    'is_points',
    'is_account',
    'is_drs_refund',
    'opens_drawer',
    'show_on_payment',
    'show_on_refund',
    'show_on_customer_payment',
    'is_active',
] as const;

export type TenderFlag = (typeof TENDER_FLAGS)[number];

export type PaymentTypeRow = { id: string; name: string; position: number; kind: string } & Record<TenderFlag, boolean>;

export interface PaymentTypeIndexProps {
    paymentTypes: Paginated<PaymentTypeRow>;
    counts: { all: number; active: number };
    canEdit: boolean;
}

export interface ReasonRow {
    id: string;
    type: string | null;
    typeLabel: string;
    text: string;
    position: number;
    is_active: boolean;
    account_code: string | null;
}

export interface ReasonIndexProps {
    reasons: Paginated<ReasonRow>;
    filters: { type: string | null };
    counts: { all: number; active: number };
    types: Option[];
    canEdit: boolean;
}

export interface StaffRow {
    id: string;
    name: string;
    role: string | null;
    isActive: boolean;
    hasPin: boolean;
    hasFob: boolean;
    branches: string[];
    ratePerHour: string | null;
    updatedAt: string | null;
}

export interface StaffIndexProps {
    staff: Paginated<StaffRow>;
    filters: { status: 'all' | 'active' | 'inactive'; role: string | null; branch: string | null };
    counts: { all: number; active: number };
    options: { roles: Option[]; branches: Option[] };
    canEdit: boolean;
}

export type StaffFormData = {
    name: string;
    role_id: string;
    is_active: boolean;
    rate_per_hour: string;
    max_shift_hours: string;
    is_service_staff: boolean;
    allow_commission: boolean;
    is_personal_licence_holder: boolean;
    big_text_mode: boolean;
    simple_mode_override: 'role' | 'on' | 'off';
    branch_ids: string[];
    pin: string;
    pin_confirmation: string;
};

export interface StaffMember extends Omit<StaffFormData, 'pin' | 'pin_confirmation'> {
    id: string;
    hasPin: boolean;
    hasFob: boolean;
    updatedAt: string | null;
}

export interface StaffFormProps {
    member: StaffMember | null;
    options: { roles: (Option & { isOwner: boolean })[]; branches: Option[] };
    canEdit: boolean;
}

export interface TillRoleRow {
    id: string;
    name: string;
    level: number;
    isSystem: boolean;
    isOwner: boolean;
    staffCount: number;
    granted: string[];
}

export interface PermissionGroup {
    label: string;
    permissions: { key: string; label: string; help: string | null }[];
}

export interface RoleEditorProps {
    roles: TillRoleRow[];
    selected: string | null;
    groups: PermissionGroup[];
    canEdit: boolean;
}
