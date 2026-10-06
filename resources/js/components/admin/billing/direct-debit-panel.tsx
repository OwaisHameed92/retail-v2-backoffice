import { DirectDebitPayments, directDebitTones as tones } from '@/components/admin/billing/direct-debit-payments';
import { DirectDebitSettingsDialog } from '@/components/admin/billing/direct-debit-settings-dialog';
import { formatDate, formatDay } from '@/components/admin/billing/format';
import { type CompanyRef, type DirectDebitData } from '@/components/admin/billing/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge, StatusPill } from '@/components/shared/status-badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { taxName } from '@/lib/country';
import { router } from '@inertiajs/react';
import { MailPlus, PauseCircle, PlayCircle, ReceiptText, RefreshCw, Settings2, TriangleAlert, XCircle } from 'lucide-react';
import { type ReactNode, useState } from 'react';

type Action = 'settings' | 'setup-email' | 'setup-fee' | 'sync' | 'pause' | 'resume' | 'cancel' | null;

function post(url: string) {
    return new Promise<void>((resolve) => router.post(url, {}, { preserveScroll: true, onFinish: () => resolve() }));
}

function Tile({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid content-start gap-1.5 rounded-lg border p-4">
            <span className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{label}</span>
            {children}
        </div>
    );
}

/** Direct Debit on the tenant Billing tab (module 1.12): mode, setup fee, mandate, subscription and GoCardless payments. */
export function DirectDebitPanel({ company, directDebit, canManage }: { company: CompanyRef; directDebit: DirectDebitData; canManage: boolean }) {
    const [action, setAction] = useState<Action>(null);
    const close = (open: boolean) => !open && setAction(null);
    const { mandate, subscription, setupFee, payments } = directDebit;
    const isDirectDebit = directDebit.mode === 'directDebit';
    // Upfront customers that never had Direct Debit only need the mode and the setup fee.
    const showDirectDebit = isDirectDebit || mandate.id !== null || payments.data.length > 0;
    // The setup fee is always paid by hand (owner rule 2026-10-05): invoice it to send it before it is paid.
    const canInvoiceSetup = setupFee.hasFee && setupFee.invoicedAt === null;
    const url = (name: string, params: Record<string, string> = {}) =>
        route(`admin.billing.tenants.direct-debit.${name}`, { company: company.id, ...params });

    return (
        <SectionCard
            title={
                <span className="inline-flex items-center gap-2">
                    Direct Debit
                    {directDebit.enabled && directDebit.environment === 'sandbox' && <StatusPill tone="violet">Sandbox</StatusPill>}
                </span>
            }
            description={
                isDirectDebit
                    ? 'GoCardless collects the monthly or yearly fee; every payment has its own invoice. The setup fee is paid by hand.'
                    : 'This business pays each period by hand (exception): cash, card or bank transfer.'
            }
            actions={
                canManage ? (
                    <div className="flex flex-wrap gap-2">
                        {isDirectDebit && !mandate.usable && (
                            <Button size="sm" onClick={() => setAction('setup-email')} disabled={!directDebit.enabled}>
                                <MailPlus />
                                {mandate.setupSentAt ? 'Send setup email again' : 'Send setup email'}
                            </Button>
                        )}
                        {canInvoiceSetup && (
                            <Button size="sm" variant="outline" onClick={() => setAction('setup-fee')}>
                                <ReceiptText />
                                Invoice setup fee
                            </Button>
                        )}
                        <Button size="sm" variant="outline" onClick={() => setAction('settings')}>
                            <Settings2 />
                            Payment settings
                        </Button>
                    </div>
                ) : undefined
            }
        >
            <div className="grid gap-5">
                {isDirectDebit && !directDebit.enabled && (
                    <Alert>
                        <TriangleAlert className="size-4" />
                        <AlertTitle>GoCardless is not connected</AlertTitle>
                        <AlertDescription>
                            Add GOCARDLESS_ACCESS_TOKEN and GOCARDLESS_WEBHOOK_SECRET to the server settings to collect by Direct Debit.
                        </AlertDescription>
                    </Alert>
                )}
                {isDirectDebit && directDebit.deadline && (
                    <Alert variant={directDebit.deadline.passed ? 'destructive' : 'default'}>
                        <TriangleAlert className="size-4" />
                        <AlertTitle>
                            {directDebit.deadline.passed
                                ? 'No Direct Debit yet: suspended by the daily billing run'
                                : `Waiting for the owner to set up Direct Debit (${directDebit.deadline.daysLeft} ${directDebit.deadline.daysLeft === 1 ? 'day' : 'days'} left)`}
                        </AlertTitle>
                        <AlertDescription>
                            The owner sets it up from Billing in their portal, or from the setup email. Deadline{' '}
                            {formatDate(directDebit.deadline.deadline)}.
                        </AlertDescription>
                    </Alert>
                )}
                {isDirectDebit && mandate.lostAt && !mandate.usable && (
                    <Alert variant="destructive">
                        <XCircle className="size-4" />
                        <AlertTitle>Direct Debit stopped ({mandate.statusLabel.toLowerCase()})</AlertTitle>
                        <AlertDescription>
                            Since {formatDate(mandate.lostAt)}. The subscription is paused and the business is suspended (tills lock) if there is no
                            new one after {directDebit.graceDays} days; a new mandate lifts it. Send the setup email again.
                        </AlertDescription>
                    </Alert>
                )}

                <div className={showDirectDebit ? 'grid gap-3 sm:grid-cols-2 xl:grid-cols-4' : 'grid gap-3 sm:grid-cols-2'}>
                    <Tile label="Billing mode">
                        <span className="font-medium">{isDirectDebit ? 'Direct Debit' : 'Upfront'}</span>
                        <span className="text-muted-foreground text-sm">
                            {isDirectDebit ? 'GoCardless, monthly or yearly' : 'Cash, card or bank transfer (exception)'}
                        </span>
                    </Tile>
                    <Tile label="Setup fee (upfront)">
                        <span className="font-medium tabular-nums">{setupFee.hasFee ? `${setupFee.gross} incl. ${taxName()}` : 'None'}</span>
                        <span className="text-muted-foreground text-sm">
                            {setupFee.hasFee
                                ? `${setupFee.override !== null ? 'Custom' : 'Plan fee'} · ${setupFee.instalments > 1 ? `${setupFee.instalments} monthly payments` : 'one payment'} · paid by hand`
                                : 'No setup fee on this plan'}
                        </span>
                        {setupFee.invoicedAt && (
                            <span className="text-success-foreground text-xs font-medium">Invoiced {formatDate(setupFee.invoicedAt)}</span>
                        )}
                    </Tile>
                    {showDirectDebit && (
                        <Tile label="Mandate">
                            <StatusBadge status={mandate.status ?? 'none'} label={mandate.statusLabel} tones={tones} className="justify-self-start" />
                            <span className="text-muted-foreground text-sm">
                                {mandate.activeAt
                                    ? `Set up ${formatDate(mandate.activeAt)}`
                                    : mandate.setupSentAt
                                      ? `Setup email sent ${formatDate(mandate.setupSentAt)}`
                                      : 'No setup email sent yet'}
                            </span>
                        </Tile>
                    )}
                    {showDirectDebit && (
                        <Tile label="Subscription">
                            <span className="font-medium tabular-nums">
                                {subscription.amount ? `${subscription.amount} ${subscription.cycle === 'yearly' ? 'a year' : 'a month'}` : '—'}
                            </span>
                            <span className="flex flex-wrap items-center gap-2 text-sm">
                                <StatusBadge status={subscription.status ?? 'none'} label={subscription.statusLabel} tones={tones} />
                                {subscription.nextChargeDate && (
                                    <span className="text-muted-foreground">Next {formatDay(subscription.nextChargeDate)}</span>
                                )}
                            </span>
                            {isDirectDebit && mandate.usable && !subscription.inStep && (
                                <span className="text-warning-foreground text-xs font-medium">
                                    Should be {subscription.expected} for {subscription.expectedUnits}
                                </span>
                            )}
                        </Tile>
                    )}
                </div>

                {canManage && isDirectDebit && mandate.usable && (
                    <div className="flex flex-wrap gap-2">
                        <Button size="sm" variant="outline" onClick={() => setAction('sync')}>
                            <RefreshCw />
                            Update subscription
                        </Button>
                        {subscription.live && subscription.status !== 'paused' && (
                            <Button size="sm" variant="outline" onClick={() => setAction('pause')}>
                                <PauseCircle />
                                Pause
                            </Button>
                        )}
                        {subscription.status === 'paused' && (
                            <Button size="sm" variant="outline" onClick={() => setAction('resume')}>
                                <PlayCircle />
                                Resume
                            </Button>
                        )}
                        {subscription.live && (
                            <Button size="sm" variant="ghost" className="text-danger-foreground" onClick={() => setAction('cancel')}>
                                <XCircle />
                                Cancel subscription
                            </Button>
                        )}
                    </div>
                )}

                {showDirectDebit && <DirectDebitPayments payments={payments.data} />}
            </div>

            {canManage && (
                <>
                    <DirectDebitSettingsDialog open={action === 'settings'} onOpenChange={close} company={company} directDebit={directDebit} />
                    <ConfirmDialog
                        open={action === 'setup-email'}
                        onOpenChange={close}
                        title="Send the Direct Debit setup email?"
                        description="The owners get a secure link to the GoCardless page. Once they finish, the subscription starts automatically (after the setup fee is paid). The setup fee is never taken by Direct Debit."
                        confirmLabel="Send email"
                        onConfirm={() => post(url('setup-email'))}
                    />
                    <ConfirmDialog
                        open={action === 'setup-fee'}
                        onOpenChange={close}
                        title={`Invoice the setup fee of ${setupFee.gross}?`}
                        description="The invoice (one per instalment) is emailed now. Record the cash, card or bank transfer with Record setup fee payment."
                        confirmLabel="Invoice setup fee"
                        onConfirm={() => post(url('setup-fee'))}
                    />
                    <ConfirmDialog
                        open={action === 'sync'}
                        onOpenChange={close}
                        title="Update the subscription?"
                        description={`It will collect ${subscription.expected} ${directDebit.subscription.cycle === 'yearly' ? 'a year' : 'a month'} for ${subscription.expectedUnits}, from the next payment.`}
                        confirmLabel="Update"
                        onConfirm={() => post(url('sync'))}
                    />
                    <ConfirmDialog
                        open={action === 'pause' || action === 'resume' || action === 'cancel'}
                        onOpenChange={close}
                        destructive={action === 'cancel'}
                        title={
                            action === 'cancel'
                                ? 'Cancel the subscription?'
                                : action === 'pause'
                                  ? 'Pause the subscription?'
                                  : 'Resume the subscription?'
                        }
                        description={
                            action === 'cancel'
                                ? 'GoCardless stops collecting. Invoices are then raised by hand by the daily billing run; "Update subscription" starts a new one.'
                                : action === 'pause'
                                  ? 'No payments are collected until you resume it.'
                                  : 'Collections carry on from the next charge date.'
                        }
                        confirmLabel={action === 'cancel' ? 'Cancel subscription' : action === 'pause' ? 'Pause' : 'Resume'}
                        onConfirm={() => post(url('subscription', { action: action ?? 'pause' }))}
                    />
                </>
            )}
        </SectionCard>
    );
}

export default DirectDebitPanel;
