import { SectionCard } from '@/components/shared/section-card';
import { Timeline, type TimelineItem } from '@/components/shared/timeline';
import { londonDateTime } from '@/components/till-health/format';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { CircleCheck, CircleSlash, CircleX, ShieldCheck } from 'lucide-react';
import { ConsentPill, dayLabel } from './format';
import { type CustomerShowProps } from './types';

const EVENT_TEXT = { given: 'agreed to', refused: 'said no to', withdrawn: 'withdrew consent for' } as const;
const EVENT_ICON = { given: CircleCheck, refused: CircleSlash, withdrawn: CircleX } as const;
const EVENT_TONE = { given: 'success', refused: 'neutral', withdrawn: 'danger' } as const;

/**
 * Marketing consent per channel with where and when it was given, and the full history (UK GDPR). Read only:
 * consent is the customer's own say-so, recorded at a till, online or on a paper form.
 */
export function ConsentPanel({ consent, name }: { consent: CustomerShowProps['consent']; name: string }) {
    const items: TimelineItem[] = consent.history.map((event) => ({
        id: event.id,
        icon: EVENT_ICON[event.event],
        tone: EVENT_TONE[event.event],
        title: (
            <>
                <strong>{name}</strong> {EVENT_TEXT[event.event]} {event.channel.toLowerCase()} marketing
            </>
        ),
        time: londonDateTime(event.at),
        body: (
            <span className="text-muted-foreground text-xs">
                {event.source}
                {event.shop ? ` · ${event.shop}` : ''}
            </span>
        ),
    }));

    return (
        <div className="grid gap-6">
            <Alert>
                <ShieldCheck className="size-4" />
                <AlertDescription>
                    Only send marketing on a channel marked Opted in. Consent is recorded when the customer gives or withdraws it at a till, online or
                    on a paper form, so it cannot be changed here. Statements and receipts are not marketing.
                </AlertDescription>
            </Alert>

            <SectionCard title="Current consent" description="The customer's latest answer on each channel." flush>
                <ul className="divide-border divide-y">
                    {consent.current.map((channel) => (
                        <li key={channel.channel} className="flex flex-col gap-1 px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <div className="grid leading-5">
                                <span className="font-medium">{channel.label}</span>
                                <span className="text-muted-foreground text-xs">
                                    {channel.state === 'none'
                                        ? 'Never asked: treat as no consent'
                                        : `${channel.state === 'given' ? 'Since' : 'On'} ${dayLabel(channel.at)} · ${channel.source}${channel.shop ? ` · ${channel.shop}` : ''}`}
                                </span>
                            </div>
                            <ConsentPill state={channel.state} />
                        </li>
                    ))}
                </ul>
            </SectionCard>

            <SectionCard title="Consent history" description="Every answer, newest first, with where it was recorded.">
                <Timeline items={items} emptyTitle="No consent recorded" emptyBody="This customer has not been asked about marketing yet." />
            </SectionCard>
        </div>
    );
}
