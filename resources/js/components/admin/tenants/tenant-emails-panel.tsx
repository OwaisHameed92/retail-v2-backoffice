import { HeldEmailHistory, HeldEmailList } from '@/components/admin/emails/held-email-list';
import { formatDate } from '@/components/admin/tenants/format';
import { type Tenant, type TenantEmailsData } from '@/components/admin/tenants/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { SectionCard } from '@/components/shared/section-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Link, router } from '@inertiajs/react';
import { KeyRound, MailCheck, PauseCircle, Send } from 'lucide-react';

const RELOAD = ['emails', 'activity', 'billing'];

function post(name: string, tenantId: string): Promise<void> {
    return new Promise((resolve) => router.post(route(name, tenantId), {}, { preserveScroll: true, only: RELOAD, onFinish: () => resolve() }));
}

/**
 * The business page's Emails tab (P11, billing.manage): every email held for this business with the values it will
 * send, "Send all held emails" once the plan, fees, tills and amounts are checked, and the first emails' buttons
 * (welcome email with the licence keys, set-password link).
 */
export function TenantEmailsPanel({ tenant, emails }: { tenant: Tenant; emails: TenantEmailsData }) {
    const count = emails.held.length;

    return (
        <div className="grid gap-4">
            {emails.manualCategories.length > 0 && (
                <Alert variant="warning">
                    <PauseCircle className="size-4" />
                    <AlertDescription>
                        Held until you send them: {emails.manualCategories.join(', ').toLowerCase()}.{' '}
                        <Link href={route('admin.settings.emails')} className="font-medium underline underline-offset-2">
                            Email settings
                        </Link>
                    </AlertDescription>
                </Alert>
            )}

            <SectionCard
                title={`Held emails${count > 0 ? ` (${count})` : ''}`}
                description={`Nothing here has reached ${tenant.name}. Check the plan, fees, tills and the values below, then send.`}
                actions={
                    count > 0 && (
                        <ConfirmDialog
                            trigger={
                                <Button size="sm">
                                    <Send />
                                    Send all held emails
                                </Button>
                            }
                            title={`Send ${count === 1 ? 'the held email' : `all ${count} held emails`} to ${tenant.name}?`}
                            description="Each goes now, oldest first, with the values shown (invoices as they stand now). Any that cannot go are listed and stay held."
                            confirmLabel={count === 1 ? 'Send email' : `Send ${count} emails`}
                            onConfirm={() => post('admin.tenants.emails.send-held', tenant.id)}
                        />
                    )
                }
            >
                <HeldEmailList
                    emails={emails.held}
                    reload={RELOAD}
                    emptyBody="Emails to this business that are not sent automatically wait here for you."
                />
            </SectionCard>

            <SectionCard title="First emails" description="Send these when the business is ready to start.">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid content-start gap-2 rounded-lg border p-4">
                        <div className="flex items-center gap-2 text-sm font-semibold">
                            <KeyRound className="text-muted-foreground size-4" />
                            Welcome email and licence keys
                        </div>
                        <p className="text-muted-foreground text-sm">
                            {emails.welcomeHeld
                                ? 'Held: it lists every till with its licence key (the only place the keys are).'
                                : emails.welcomeSentAt
                                  ? `Sent on ${formatDate(emails.welcomeSentAt)}. To send keys again use “Resend key” on a licence (it makes a new key).`
                                  : 'No welcome email is waiting. Keys can be sent with “Resend key” on each licence.'}
                        </p>
                        <div>
                            <ConfirmDialog
                                trigger={
                                    <Button size="sm" variant="outline" disabled={!emails.welcomeHeld}>
                                        <Send />
                                        Send welcome email
                                    </Button>
                                }
                                title={`Send the welcome email to ${tenant.name}?`}
                                description="The owner gets every till’s licence key now."
                                confirmLabel="Send welcome email"
                                onConfirm={() => post('admin.tenants.emails.welcome', tenant.id)}
                            />
                        </div>
                    </div>
                    <div className="grid content-start gap-2 rounded-lg border p-4">
                        <div className="flex items-center gap-2 text-sm font-semibold">
                            <MailCheck className="text-muted-foreground size-4" />
                            Set-password link
                        </div>
                        <p className="text-muted-foreground text-sm">
                            {emails.passwordHeld
                                ? 'Held: sent with a new 7-day link.'
                                : 'Sends every active owner a new 7-day link to set their password.'}
                        </p>
                        <div>
                            <ConfirmDialog
                                trigger={
                                    <Button size="sm" variant="outline">
                                        <Send />
                                        Send set-password link
                                    </Button>
                                }
                                title="Send the set-password link?"
                                description={
                                    emails.passwordHeld
                                        ? 'The held link emails go now, each with a new 7-day link.'
                                        : `Every active owner of ${tenant.name} gets a new 7-day link. An older link stops working.`
                                }
                                confirmLabel="Send link"
                                onConfirm={() => post('admin.tenants.emails.password-link', tenant.id)}
                            />
                        </div>
                    </div>
                </div>
            </SectionCard>

            <SectionCard title="Sent or discarded from here">
                <HeldEmailHistory emails={emails.recent} />
            </SectionCard>
        </div>
    );
}

export default TenantEmailsPanel;
