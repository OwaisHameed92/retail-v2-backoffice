import { type StatusToneMap } from '@/components/shared/status-badge';

export type EmailStatus = 'queued' | 'sent' | 'failed' | 'suppressed';

/** A row from App\Http\Controllers\Admin\EmailLogController::row(). */
export interface EmailLogRow {
    id: string;
    to: string;
    template: string;
    templateLabel: string;
    subject: string | null;
    company: { id: string; name: string } | null;
    status: EmailStatus;
    error: string | null;
    messageId: string | null;
    mailable: string;
    meta: Record<string, string | number | boolean | null>;
    isTest: boolean;
    createdAt: string | null;
    sentAt: string | null;
    updatedAt: string | null;
}

export interface EmailLogFilters {
    template: string | null;
    status: EmailStatus | null;
    from: string | null;
    to: string | null;
}

export interface EmailSummary {
    sent: number;
    failed: number;
    queued: number;
}

export interface Option {
    value: string;
    label: string;
}

/** From App\Domain\Mail\Support\EmailTemplates::all(). */
export interface EmailTemplateItem {
    key: string;
    label: string;
    description: string;
    audience: 'customer' | 'staff';
    subject: string;
}

export interface EmailToast {
    id: string;
    message: string;
}

export const emailStatusTones: StatusToneMap = {
    queued: 'info',
    sent: 'success',
    failed: 'danger',
    suppressed: 'neutral',
};
