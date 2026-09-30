import { StatusPill, type StatusTone } from '@/components/shared/status-badge';

const TAKE_STATUS: Record<string, { label: string; tone: StatusTone }> = {
    draft: { label: 'Draft', tone: 'neutral' },
    counting: { label: 'Counting', tone: 'info' },
    review: { label: 'In review', tone: 'warning' },
    approved: { label: 'Approved', tone: 'success' },
    cancelled: { label: 'Cancelled', tone: 'danger' },
};

export const TAKE_STATUSES = Object.entries(TAKE_STATUS).map(([value, s]) => ({ value, label: s.label }));

export const TAKE_SCOPES: Record<string, string> = {
    branch: 'Whole shop',
    department: 'One department',
    category: 'One category',
    supplier: 'One supplier',
    productList: 'Chosen products',
};

export function TakeStatusPill({ status }: { status: string | null }) {
    const s = TAKE_STATUS[status ?? ''] ?? { label: 'Unknown', tone: 'neutral' as StatusTone };

    return <StatusPill tone={s.tone}>{s.label}</StatusPill>;
}
