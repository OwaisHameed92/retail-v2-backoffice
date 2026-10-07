import { type StatusToneMap } from '@/components/shared/status-badge';

export type EmailStatus = 'queued' | 'sent' | 'failed' | 'suppressed' | 'held' | 'discarded';

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
    held: 'warning',
    discarded: 'neutral',
};

/** HeldEmailData::row (P11): an email waiting for an admin, with the values it will send. */
export interface HeldEmailRow {
    id: string;
    company: { id: string; name: string } | null;
    category: string;
    categoryLabel: string;
    template: string;
    templateLabel: string;
    to: string;
    subject: string | null;
    facts: { label: string; value: string }[];
    /** Why it cannot be sent as it is (a void invoice…); discard it. */
    problem: string | null;
    status: 'held' | 'sent' | 'discarded';
    createdAt: string | null;
    actionedAt: string | null;
    actionedBy: string | null;
}

/** Admin → Settings → Emails (P11). */
export interface EmailCategorySetting {
    value: string;
    label: string;
    description: string;
    sendAutomatically: boolean;
    held: number;
}
