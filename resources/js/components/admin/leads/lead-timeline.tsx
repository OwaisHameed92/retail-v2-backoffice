import { InitialsAvatar } from '@/components/shared/entity-cell';
import { SectionCard } from '@/components/shared/section-card';
import { Timeline, type TimelineItem, type TimelineTone } from '@/components/shared/timeline';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import {
    BadgeCheck,
    CalendarClock,
    CircleX,
    CopyCheck,
    Inbox,
    LoaderCircle,
    MessageSquareText,
    Pencil,
    PhoneCall,
    RotateCcw,
    UserRound,
    type LucideIcon,
} from 'lucide-react';
import { type FormEventHandler, type KeyboardEvent } from 'react';
import { formatDateTimeShort } from './format';
import { type LeadNoteRow } from './types';

const NOTE_MAX = 5000;

function look(note: LeadNoteRow): { icon: LucideIcon; tone: TimelineTone } {
    const to = note.meta?.to;

    switch (note.kind) {
        case 'note':
            return { icon: MessageSquareText, tone: 'primary' };
        case 'created':
            return { icon: Inbox, tone: 'neutral' };
        case 'assigned':
            return { icon: UserRound, tone: 'neutral' };
        case 'followUp':
            return { icon: CalendarClock, tone: 'neutral' };
        case 'updated':
            return { icon: Pencil, tone: 'neutral' };
        case 'duplicate':
            return { icon: CopyCheck, tone: 'warning' };
        case 'statusChanged':
            if (to === 'converted') return { icon: BadgeCheck, tone: 'success' };
            if (to === 'rejected') return { icon: CircleX, tone: 'danger' };
            if (note.meta?.from === 'rejected') return { icon: RotateCcw, tone: 'neutral' };

            return { icon: PhoneCall, tone: 'primary' };
    }
}

function item(note: LeadNoteRow): TimelineItem {
    const { icon, tone } = look(note);
    const who = note.author?.name ?? 'System';

    if (note.kind === 'note') {
        return {
            id: note.id,
            icon,
            tone,
            title: (
                <>
                    <strong className="font-medium">{who}</strong> added a note
                </>
            ),
            time: formatDateTimeShort(note.createdAt),
            body: <p className="bg-subtle rounded-lg border px-3 py-2.5 text-sm leading-6 break-words whitespace-pre-line">{note.body}</p>,
        };
    }

    return {
        id: note.id,
        icon,
        tone,
        title: (
            <>
                {note.author ? <strong className="font-medium">{who}</strong> : <span className="text-muted-foreground">System</span>}
                <span className="text-muted-foreground"> · </span>
                <span className="break-words">{note.body}</span>
            </>
        ),
        time: formatDateTimeShort(note.createdAt),
    };
}

function Composer({ leadId, authorName }: { leadId: string; authorName: string }) {
    const { data, setData, post, processing, errors, reset } = useForm({ body: '' });

    const send = () => {
        if (data.body.trim() === '' || processing) return;
        post(route('admin.leads.notes.store', leadId), { preserveScroll: true, onSuccess: () => reset() });
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        send();
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
            event.preventDefault();
            send();
        }
    };

    return (
        <form onSubmit={submit} className="flex gap-3">
            <InitialsAvatar name={authorName} className="mt-0.5 hidden sm:inline-flex" />
            <div className="grid min-w-0 flex-1 gap-2">
                <label htmlFor="lead-note" className="sr-only">
                    Add a note
                </label>
                <Textarea
                    id="lead-note"
                    rows={3}
                    maxLength={NOTE_MAX}
                    placeholder="Add a note: what they said, what happens next…"
                    value={data.body}
                    onChange={(event) => setData('body', event.target.value)}
                    onKeyDown={onKeyDown}
                    aria-invalid={!!errors.body}
                    aria-describedby="lead-note-help"
                />
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p id="lead-note-help" className={errors.body ? 'text-danger-foreground text-[13px]' : 'text-muted-foreground text-[13px]'}>
                        {errors.body ?? 'Only staff see notes. Press Ctrl+Enter (⌘+Enter on Mac) to add.'}
                    </p>
                    <Button type="submit" size="sm" disabled={processing || data.body.trim() === ''}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Add note
                    </Button>
                </div>
            </div>
        </form>
    );
}

/** Notes and everything that happened to the lead, newest first, with the note composer on top. */
export function LeadTimeline({
    leadId,
    notes,
    canWrite,
    authorName,
}: {
    leadId: string;
    notes: LeadNoteRow[];
    canWrite: boolean;
    authorName: string;
}) {
    return (
        <SectionCard
            title="Notes and activity"
            description={notes.length >= 200 ? 'The latest 200 entries.' : 'Calls, notes and every change, newest first.'}
            contentClassName="grid gap-6"
        >
            {canWrite && <Composer leadId={leadId} authorName={authorName} />}
            <Timeline items={notes.map(item)} emptyTitle="Nothing yet" emptyBody="Notes and changes to this lead appear here." />
        </SectionCard>
    );
}
