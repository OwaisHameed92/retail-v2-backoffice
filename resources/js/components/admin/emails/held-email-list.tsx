import { type HeldEmailRow } from '@/components/admin/emails/types';
import { formatDateTime } from '@/components/admin/format';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { EmptyState } from '@/components/shared/empty-state';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Link, router } from '@inertiajs/react';
import { AlertTriangle, Eye, MailCheck, Send, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface HeldEmailListProps {
    emails: HeldEmailRow[];
    /** Show which business each email is for (the global list). */
    showCompany?: boolean;
    /** Props reloaded after a send or discard. */
    reload?: string[];
    emptyTitle?: string;
    emptyBody?: string;
}

/**
 * Held emails (P11) with the exact values each will send, so the owner can check them first: recipient, subject,
 * amounts, dates, tills (keys only by their last 4). Preview shows the email as it will go; Send and Discard confirm.
 */
export function HeldEmailList({
    emails,
    showCompany = false,
    reload,
    emptyTitle = 'No emails waiting',
    emptyBody = 'Emails held because their kind is not sent automatically appear here.',
}: HeldEmailListProps) {
    const [preview, setPreview] = useState<HeldEmailRow | null>(null);

    if (emails.length === 0) {
        return <EmptyState icon={MailCheck} title={emptyTitle} body={emptyBody} bordered />;
    }

    const act = (email: HeldEmailRow, action: 'send' | 'discard') =>
        new Promise<void>((resolve) =>
            router.post(
                route(`admin.emails.held.${action}`, email.id),
                {},
                { preserveScroll: true, ...(reload ? { only: reload } : {}), onFinish: () => resolve() },
            ),
        );

    return (
        <>
            <ul className="divide-border grid divide-y rounded-lg border" aria-label="Held emails">
                {emails.map((email) => (
                    <li key={email.id} className="grid gap-3 p-4 sm:p-5">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div className="min-w-0 space-y-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-sm font-semibold">{email.templateLabel}</span>
                                    <Badge variant="warning">Held</Badge>
                                    {email.categoryLabel !== email.templateLabel && <Badge variant="neutral">{email.categoryLabel}</Badge>}
                                </div>
                                <p className="text-sm break-words">{email.subject ?? '(no subject)'}</p>
                                <p className="text-muted-foreground text-sm break-words">
                                    To {email.to}
                                    {showCompany && email.company && (
                                        <>
                                            {' · '}
                                            <Link
                                                href={route('admin.tenants.show', email.company.id) + '?tab=emails'}
                                                className="text-primary hover:underline"
                                            >
                                                {email.company.name}
                                            </Link>
                                        </>
                                    )}
                                    {' · held '}
                                    {formatDateTime(email.createdAt)}
                                </p>
                            </div>
                            <div className="flex shrink-0 flex-wrap gap-2">
                                <Button size="sm" variant="outline" onClick={() => setPreview(email)}>
                                    <Eye />
                                    Preview
                                </Button>
                                <ConfirmDialog
                                    trigger={
                                        <Button size="sm" disabled={email.problem !== null}>
                                            <Send />
                                            Send
                                        </Button>
                                    }
                                    title={`Send “${email.subject ?? email.templateLabel}”?`}
                                    description={`It goes to ${email.to} now, with the values shown. This cannot be undone.`}
                                    confirmLabel="Send email"
                                    onConfirm={() => act(email, 'send')}
                                />
                                <ConfirmDialog
                                    trigger={
                                        <Button size="sm" variant="outline">
                                            <Trash2 />
                                            Discard
                                        </Button>
                                    }
                                    title="Discard this email?"
                                    description={`${email.to} will not get “${email.subject ?? email.templateLabel}”. It stays in the email log as discarded.`}
                                    confirmLabel="Discard email"
                                    destructive
                                    onConfirm={() => act(email, 'discard')}
                                />
                            </div>
                        </div>

                        {email.facts.length > 0 && (
                            <dl className="bg-subtle grid grid-cols-1 gap-x-6 gap-y-2 rounded-md px-4 py-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                                {email.facts.map((fact) => (
                                    <div key={fact.label} className="min-w-0">
                                        <dt className="text-muted-foreground text-xs">{fact.label}</dt>
                                        <dd className="font-medium break-words tabular-nums">{fact.value}</dd>
                                    </div>
                                ))}
                            </dl>
                        )}

                        {email.problem && (
                            <Alert variant="destructive">
                                <AlertTriangle className="size-4" />
                                <AlertDescription>{email.problem}</AlertDescription>
                            </Alert>
                        )}
                    </li>
                ))}
            </ul>

            <Dialog open={preview !== null} onOpenChange={(open) => !open && setPreview(null)}>
                <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>{preview?.subject ?? 'Preview'}</DialogTitle>
                        <DialogDescription>
                            To {preview?.to}. Exactly what Send will send now
                            {preview?.template === 'set-password' ? ' (the password link is made when it is sent)' : ''}.
                        </DialogDescription>
                    </DialogHeader>
                    {preview && (
                        <iframe
                            src={route('admin.emails.held.preview', preview.id)}
                            title={`Preview of ${preview.subject ?? preview.templateLabel}`}
                            sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                            className="block h-[65vh] w-full rounded-md border"
                            style={{ colorScheme: 'light' }}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

/** Sent and discarded held emails, newest first: who did it and when. */
export function HeldEmailHistory({ emails, showCompany = false }: { emails: HeldEmailRow[]; showCompany?: boolean }) {
    if (emails.length === 0) {
        return <p className="text-muted-foreground text-sm">Nothing sent or discarded from here yet.</p>;
    }

    return (
        <ul className="divide-border grid divide-y text-sm">
            {emails.map((email) => (
                <li key={email.id} className="flex flex-col gap-1 py-2.5 sm:flex-row sm:items-center sm:justify-between">
                    <div className="min-w-0">
                        <span className="font-medium">{email.subject ?? email.templateLabel}</span>
                        <span className="text-muted-foreground">
                            {' · '}
                            {email.to}
                            {showCompany && email.company ? ` · ${email.company.name}` : ''}
                        </span>
                    </div>
                    <div className="text-muted-foreground flex shrink-0 items-center gap-2">
                        <Badge variant={email.status === 'sent' ? 'success' : 'neutral'}>{email.status === 'sent' ? 'Sent' : 'Discarded'}</Badge>
                        <span>
                            {formatDateTime(email.actionedAt)}
                            {email.actionedBy ? ` by ${email.actionedBy}` : ''}
                        </span>
                    </div>
                </li>
            ))}
        </ul>
    );
}

export default HeldEmailList;
