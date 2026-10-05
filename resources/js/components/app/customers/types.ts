import { type CustomerPrivacyProps } from '@/components/app/privacy/types';
import { type Paginated } from '@/components/shared/data-table';

/** Module 4.4 props (App\Domain\Customers\Queries\CustomerList, CustomerDetail, CustomerStatement). */
export interface Option {
    value: string;
    label: string;
}

export type ConsentChannel = 'email' | 'sms' | 'whatsApp' | 'post';

export interface CustomerRow {
    id: string;
    name: string;
    cardNo: string | null;
    phone: string | null;
    email: string | null;
    tier: string | null;
    balance: string;
    points: number;
    creditLimit: string;
    isActive: boolean;
    anonymised: boolean;
    updatedAt: string | null;
}

export interface CustomerFilters {
    status: 'active' | 'inactive' | 'everyone';
    balance: 'owes' | 'credit' | 'overLimit' | null;
    points: 'has' | null;
    consent: ConsentChannel | null;
    shop: string | null;
}

export interface CustomerIndexProps {
    customers: Paginated<CustomerRow>;
    filters: CustomerFilters;
    shops: Option[];
    counts: { all: number; owing: number; owed: string; creditHeld: string; points: number; emailConsent: number };
    canEdit: boolean;
}

export type CustomerFormData = {
    name: string;
    phone: string;
    email: string;
    address: string;
    dob: string;
    card_no: string;
    credit_limit: string;
    tier: string;
    notes: string;
    is_active: boolean;
};

export interface CustomerDetails extends CustomerFormData {
    id: string;
    anonymisedAt: string | null;
    createdAt: string | null;
    updatedAt: string | null;
}

export interface LedgerRow {
    id: string;
    at: string;
    type: string | null;
    typeLabel: string;
    shop: string;
    note: string | null;
    /** How a payment or advance was taken, as the till wrote it ("Cash", "Card"; free text). */
    tender: string | null;
    saleId: string | null;
    amount: string;
    points: number;
    /** The portal's running figure after this row (null when the list is filtered). */
    balanceAfter: string | null;
    pointsAfter: number | null;
}

export interface ShopTotal {
    branch: string;
    balance: string;
    points: number;
    count: number;
    lastAt: string | null;
}

export interface ConsentState {
    channel: ConsentChannel;
    label: string;
    state: 'given' | 'withdrawn' | 'none';
    at: string | null;
    source: string | null;
    shop: string | null;
}

export interface ConsentEvent {
    id: string;
    channel: string;
    event: 'given' | 'refused' | 'withdrawn';
    at: string;
    source: string;
    shop: string | null;
}

/** A current pay date (till 0.1.51 AccountPayDate) and its reminder state. */
export interface PayDate {
    id: string;
    dueAt: string;
    wholeAccount: boolean;
    saleId: string | null;
    note: string | null;
    shop: string;
    reminder: {
        state: 'sent' | 'failed' | 'none';
        at: string | null;
        channel: string | null;
        attempts: number;
        error: string | null;
        detail: string | null;
    };
}

export interface CustomerShowProps {
    customer: CustomerDetails;
    account: {
        balance: string;
        points: number;
        creditLimit: string;
        available: string | null;
        overLimit: boolean;
        byShop: ShopTotal[];
    };
    payDates: PayDate[];
    ledger: Paginated<LedgerRow>;
    ledgerFilters: { shop: string | null; type: 'account' | 'points' | null };
    shops: Option[];
    consent: { current: ConsentState[]; history: ConsentEvent[] };
    canEdit: boolean;
    canEmail: boolean;
    /** Owners only (privacy.manage, module 7.7). */
    privacy: CustomerPrivacyProps | null;
    readOnlyReason: 'oneShop' | null;
}

export interface Statement {
    business: { name: string; address: string; phone: string | null; email: string | null; vatNumber: string | null };
    customer: { id: string; name: string; address: string | null; email: string | null; cardNo: string | null };
    from: string;
    to: string;
    period: string;
    issued: string;
    opening: { balance: string; points: number };
    rows: LedgerRow[];
    totals: { charges: string; credits: string; pointsEarned: number; pointsUsed: number };
    closing: { balance: string; points: number };
}

export interface StatementProps {
    statement: Statement;
    canEmail: boolean;
}
