import { type InvoiceStatus } from '@/components/admin/billing/types';
import { type BillingStatusData } from '@/components/shared/billing-status-card';
import type { AiUsage } from './ai-usage-card';

/** Props of `app/billing` (App\Domain\Billing\Data\PortalBilling + PortalSubscription, modules 1.13 and 4.10). */
export interface PortalBillingProps {
    /** This month's AI allowance and use (module 6.2). */
    aiUsage?: AiUsage;
    businessName: string;
    /** The Billing status card (same as the admin Billing tab). */
    status: BillingStatusData;
    plan: string | null;
    pricing: {
        mode: 'perTill' | 'perBranch';
        modeLabel: string;
        unit: string;
        unitPrice: string | null;
        units: number;
        unitsLabel: string;
        tills: number;
        cycle: 'monthly' | 'yearly';
        per: string;
        net: string;
        vat: string;
        gross: string;
        vatApplies: boolean;
        isZero: boolean;
    };
    /** The setup fee (upfront): one thing, always paid by hand. Money formatted, VAT included. */
    upfront: {
        recorded: boolean;
        amount: string | null;
        method: string | null;
        recordedAt: string | null;
        status: 'none' | 'unpaid' | 'partPaid' | 'paid';
        statusLabel: string;
        total: string;
        owed: string;
    };
    directDebit: {
        directDebit: boolean;
        available: boolean;
        mandate: { usable: boolean; status: string | null; statusLabel: string; activeAt: string | null; lost: boolean };
        nextCollection: { date: string; amount: string } | null;
        deadline: { deadline: string; daysLeft: number; passed: boolean } | null;
        canSetUp: boolean;
    };
    invoices: {
        id: string;
        number: string | null;
        kind: string;
        period: string | null;
        issueDate: string | null;
        dueDate: string | null;
        total: string;
        balance: string;
        status: InvoiceStatus;
        statusLabel: string;
    }[];
    account: {
        status: string;
        statusLabel: string;
        trialEndsAt: string | null;
        customerSince: string | null;
        tills: number;
        shops: number;
        cancelled: boolean;
    };
    payments: { id: string; number: string; receivedAt: string; method: string; amount: string; reversed: boolean }[];
    collections: { id: string; chargeDate: string | null; amount: string; what: string; statusLabel: string }[];
    setupFee: {
        charged: boolean;
        method: 'manual' | null;
        total: string;
        parts: { label: string; dueDate: string | null; amount: string; status: string; statusLabel: string; number: string | null }[];
    } | null;
    requests: {
        id: string;
        kind: BillingRequestKind;
        kindLabel: string;
        requestedBy: string | null;
        sentAt: string;
        count: number;
        done: boolean;
        doneAt: string | null;
    }[];
    canRequest: boolean;
}

export type BillingRequestKind = 'cancel' | 'changeBank';
