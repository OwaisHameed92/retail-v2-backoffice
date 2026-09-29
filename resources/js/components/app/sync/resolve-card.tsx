import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { SectionCard } from '@/components/shared/section-card';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/react';
import { Check, Store, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { type ConflictDetailProps, type ConflictResolution } from './types';

const copy: Record<ConflictResolution, { title: string; body: string; confirm: string; icon: typeof Check }> = {
    keepPortal: {
        title: "Keep the portal's version?",
        body: "Nothing changes: the portal's version stays, and the shop receives it at its next sync.",
        confirm: "Keep portal's version",
        icon: Check,
    },
    useTill: {
        title: "Use the shop's version?",
        body: "The shop's version replaces the portal's and is sent to every till of the business at their next sync.",
        confirm: "Use shop's version",
        icon: Store,
    },
    acknowledged: {
        title: 'Mark as reviewed?',
        body: "Nothing is applied: the portal's record stays as it is. The conflict moves to Resolved.",
        confirm: 'Mark as reviewed',
        icon: Undo2,
    },
};

/** The choices for an open conflict, each behind a confirmation, with an optional note kept in the audit log. */
export function ResolveCard({ conflict, resolutions }: Pick<ConflictDetailProps, 'conflict' | 'resolutions'>) {
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | null>(null);

    const submit = (resolution: ConflictResolution) =>
        new Promise<void>((done) =>
            router.post(
                route('app.sync.conflicts.resolve', conflict.id),
                { resolution, note: note.trim() || null },
                {
                    preserveScroll: true,
                    onError: (errors) => setError(Object.values(errors)[0] ?? 'This conflict could not be resolved.'),
                    onFinish: () => done(),
                },
            ),
        );

    return (
        <SectionCard
            title="Settle this conflict"
            description="The portal's version is already in place everywhere. Confirm it, or take the shop's version instead."
        >
            <div className="flex flex-col gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="resolution-note">
                        Note <span className="text-muted-foreground font-normal">(optional)</span>
                    </Label>
                    <Textarea
                        id="resolution-note"
                        value={note}
                        maxLength={500}
                        onChange={(event) => setNote(event.target.value)}
                        placeholder="Why you chose this, for whoever looks next"
                        rows={2}
                    />
                </div>
                {error && (
                    <p role="alert" className="text-destructive text-sm">
                        {error}
                    </p>
                )}
                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    {resolutions.map((option, index) => {
                        const text = copy[option.value];
                        const Icon = text.icon;
                        const primary = index === 0;

                        return (
                            <ConfirmDialog
                                key={option.value}
                                trigger={
                                    <Button variant={primary ? 'default' : 'outline'}>
                                        <Icon />
                                        {text.confirm}
                                    </Button>
                                }
                                title={text.title}
                                description={text.body}
                                confirmLabel={text.confirm}
                                onConfirm={() => submit(option.value)}
                            />
                        );
                    })}
                </div>
            </div>
        </SectionCard>
    );
}
