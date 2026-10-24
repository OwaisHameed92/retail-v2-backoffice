import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/country';
import { router } from '@inertiajs/react';
import { LoaderCircle, MailCheck, Send, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { type StoreEmailSettings } from './email-types';

interface EmailStatusCardProps {
    store: StoreEmailSettings;
    testRecipient: string;
    canEdit: boolean;
    /** Unsaved changes: the test email would use the saved settings, so it waits. */
    dirty: boolean;
}

/**
 * OS-10: how customer emails go out now (Switch & Save's service in the shop's name, own server waiting for a test
 * email, or the own server), the last problem with the own server, and "Send a test email" (to the signed-in user).
 */
export function EmailStatusCard({ store, testRecipient, canEdit, dirty }: EmailStatusCardProps) {
    const [sending, setSending] = useState(false);
    const { status, smtp } = store;
    const sender = store.senderName || store.businessName;
    const replyTo = store.replyTo || store.orderAddress;

    const sendTest = () => {
        setSending(true);
        router.post(route('app.online-store.emails.test'), {}, { preserveScroll: true, onFinish: () => setSending(false) });
    };

    const summary = {
        platform: {
            pill: <StatusPill tone="info">Switch &amp; Save email service</StatusPill>,
            text: (
                <>
                    Customers get emails from <strong className="text-foreground">{sender}</strong> &lt;{store.platformFrom}&gt;.
                </>
            ),
        },
        needsTest: {
            pill: <StatusPill tone="warning">Waiting for a test email</StatusPill>,
            text: <>Your email server is saved but not used yet. Send a test email: once it arrives, customer emails go from your server.</>,
        },
        ownServer: {
            pill: <StatusPill tone="success">Your own email server</StatusPill>,
            text: (
                <>
                    Customers get emails from <strong className="text-foreground">{sender}</strong> &lt;{smtp.fromAddress}&gt; through {smtp.host}
                    {status.verifiedAt ? `, tested ${formatDateTime(status.verifiedAt)}` : ''}.
                </>
            ),
        },
    }[status.mode];

    return (
        <SectionCard
            title="How your emails are sent"
            description="Order received, ready to collect and cancelled emails go to your customers in your shop's name."
            actions={
                canEdit && (
                    <Button
                        variant="outline"
                        onClick={sendTest}
                        disabled={sending || dirty}
                        title={dirty ? 'Save your changes first' : `Sends a sample order email to ${testRecipient}`}
                    >
                        {sending ? <LoaderCircle className="animate-spin" /> : <Send />}
                        Send a test email
                    </Button>
                )
            }
        >
            <div className="grid gap-4">
                <div className="flex flex-wrap items-start gap-3">
                    <MailCheck className="text-muted-foreground mt-0.5 size-5 shrink-0" aria-hidden />
                    <div className="grid min-w-0 gap-1.5">
                        <div>{summary.pill}</div>
                        <p className="text-muted-foreground text-sm leading-6 break-words">{summary.text}</p>
                        <p className="text-muted-foreground text-sm leading-6 break-words">
                            {replyTo ? (
                                <>
                                    Replies go to <span className="text-foreground">{replyTo}</span>.
                                </>
                            ) : (
                                'Add a reply-to address so customers can answer your emails.'
                            )}{' '}
                            {canEdit && `The test email goes to ${testRecipient}.`}
                        </p>
                    </div>
                </div>

                {status.lastError && (
                    <Alert variant="destructive">
                        <TriangleAlert />
                        <AlertTitle>
                            Problem with your email server{status.lastErrorAt ? ` on ${formatDateTime(status.lastErrorAt)}` : ''}
                        </AlertTitle>
                        <AlertDescription>
                            <p className="break-words">{status.lastError}</p>
                            <p className="mt-1">
                                Customer emails went through Switch &amp; Save&apos;s email service instead, so your customers still got them. Fix
                                the settings below and send a test email.
                            </p>
                        </AlertDescription>
                    </Alert>
                )}
            </div>
        </SectionCard>
    );
}
