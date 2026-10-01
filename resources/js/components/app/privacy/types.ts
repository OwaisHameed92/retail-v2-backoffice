/** Matches App\Domain\Privacy\Queries\PrivacyPage, CustomerPrivacy (module 7.7). */
import { type Paginated } from '@/components/shared/data-table';

export interface TillStep {
    key: string;
    text: string;
    count: number;
    shops: string[];
}

export interface DataRequestRow {
    id: string;
    type: 'export' | 'erasure';
    typeLabel: string;
    status: 'completed' | 'tillPending';
    statusLabel: string;
    source: 'owner' | 'retention';
    customerId: string | null;
    customerName: string;
    customerExists: boolean;
    requestedBy: string;
    tillSteps: TillStep[];
    note: string | null;
    createdAt: string | null;
    completedAt: string | null;
}

export interface RetentionSettings {
    retentionMonths: number | null;
    autoAnonymise: boolean;
    dueCount: number;
    lastCheckedAt: string | null;
    cutoff: string | null;
    minMonths: number;
    maxMonths: number;
}

export interface DueCustomer {
    id: string;
    name: string;
    lastActivity: string | null;
    settled: boolean;
}

export interface PrivacyIndexProps {
    requests: Paginated<DataRequestRow>;
    filters: { type: string | null; status: string | null };
    settings: RetentionSettings;
    due: DueCustomer[];
    pendingCount: number;
}

export interface CustomerPrivacyProps {
    requests: DataRequestRow[];
    balance: string;
    settled: boolean;
    anonymised: boolean;
}
