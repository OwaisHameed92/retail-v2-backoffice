import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { zonedDateFormat } from '@/lib/country';
import { dayLabel } from './format';
import { type PayDate } from './types';

const shopTime = () => zonedDateFormat('en-GB', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });

function ReminderState({ reminder }: { reminder: PayDate['reminder'] }) {
    if (reminder.state === 'failed') {
        return (
            <div className="grid justify-items-end gap-1 text-right">
                <StatusPill tone="danger">Reminder failed</StatusPill>
                <span className="text-muted-foreground text-xs">
                    {reminder.error}
                    {reminder.attempts > 1 && ` · ${reminder.attempts} tries`}
                    {reminder.at && ` · ${shopTime().format(new Date(reminder.at))}`}
                </span>
                {reminder.detail && <span className="text-muted-foreground text-xs whitespace-pre-line">{reminder.detail}</span>}
            </div>
        );
    }

    if (reminder.state === 'sent') {
        return (
            <div className="grid justify-items-end gap-1 text-right">
                <StatusPill tone="success">Reminder sent</StatusPill>
                <span className="text-muted-foreground text-xs">
                    {[reminder.channel, reminder.at && shopTime().format(new Date(reminder.at))].filter(Boolean).join(' · ')}
                </span>
            </div>
        );
    }

    return <StatusPill tone="neutral">No reminder yet</StatusPill>;
}

/** When the customer said they will pay (till 0.1.51 pay dates) and how the reminders went. Read only: set at a till. */
export function PayDatesCard({ payDates }: { payDates: PayDate[] }) {
    if (payDates.length === 0) {
        return null;
    }

    return (
        <SectionCard title="Pay dates" description="When the customer said they will pay, as recorded at a till, and the last reminder sent." flush>
            <ul className="divide-border divide-y">
                {payDates.map((p) => (
                    <li key={p.id} className="flex flex-wrap items-start justify-between gap-4 px-5 py-3 text-sm sm:px-6">
                        <div className="grid leading-5">
                            <span className="font-medium">Due {dayLabel(p.dueAt)}</span>
                            <span className="text-muted-foreground text-xs">
                                {[p.wholeAccount ? 'Whole account' : 'One account sale', p.shop, p.note].filter(Boolean).join(' · ')}
                            </span>
                        </div>
                        <ReminderState reminder={p.reminder} />
                    </li>
                ))}
            </ul>
        </SectionCard>
    );
}
