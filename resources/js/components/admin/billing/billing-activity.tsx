import { formatDateTimeShort } from '@/components/admin/billing/format';
import { type ActivityRow } from '@/components/admin/billing/types';
import { Timeline, type TimelineItem, type TimelineTone } from '@/components/shared/timeline';
import { Ban, Banknote, CalendarCheck, FilePen, FilePlus2, FileX2, History, Mail, PauseCircle, Receipt, ReceiptText, Settings2, TriangleAlert, type LucideIcon } from 'lucide-react';

const ICONS: Record<string, [LucideIcon, TimelineTone]> = {
    'invoice.created': [FilePlus2, 'neutral'],
    'invoice.updated': [FilePen, 'neutral'],
    'invoice.deleted': [FileX2, 'neutral'],
    'invoice.issued': [Receipt, 'primary'],
    'invoice.sent': [Mail, 'primary'],
    'invoice.overdue': [TriangleAlert, 'warning'],
    'invoice.paid': [CalendarCheck, 'success'],
    'invoice.licences_renewed': [CalendarCheck, 'success'],
    'invoice.voided': [Ban, 'danger'],
    'credit_note.issued': [ReceiptText, 'primary'],
    'payment.recorded': [Banknote, 'success'],
    'billing.credit_applied': [Banknote, 'success'],
    'billing.company_suspended': [PauseCircle, 'danger'],
    'billing.settings_updated': [Settings2, 'neutral'],
};

/** Audit trail of an invoice or payment as a timeline, newest first. */
export function BillingActivity({ rows }: { rows: ActivityRow[] }) {
    const items: TimelineItem[] = rows.map((row) => {
        const [icon, tone] = ICONS[row.action] ?? [History, 'neutral'];

        return {
            id: row.id,
            icon,
            tone,
            title: (
                <>
                    {row.description}
                    <span className="text-muted-foreground"> · {row.actorName}</span>
                </>
            ),
            time: formatDateTimeShort(row.createdAt),
        };
    });

    return <Timeline items={items} emptyTitle="No activity yet" emptyBody="Changes to this record appear here." />;
}

export default BillingActivity;
