import { UpfrontBody } from '@/components/admin/billing/pricing-panel';
import { RecordPaymentDialog } from '@/components/admin/billing/record-payment-dialog';
import { type CompanyRef, type TenantBillingData } from '@/components/admin/billing/types';
import { BillingStatusCard, type BillingStatusAction } from '@/components/shared/billing-status-card';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { router } from '@inertiajs/react';
import { Banknote, MailPlus, RefreshCw } from 'lucide-react';
import { useState } from 'react';

const icons = { recordSetupPayment: Banknote, recordPayment: Banknote, sendDirectDebitLink: MailPlus, retryPayment: RefreshCw };

function post(url: string) {
    return new Promise<void>((resolve) => router.post(url, {}, { preserveScroll: true, onFinish: () => resolve() }));
}

/** The Billing status card on the tenant Billing tab, with its next action wired to the existing dialogs. */
export function TenantBillingStatus({ company, billing }: { company: CompanyRef; billing: TenantBillingData }) {
    const [open, setOpen] = useState<BillingStatusAction | null>(null);
    const close = (value: boolean) => !value && setOpen(null);
    const { status, directDebit, canManage } = billing;
    const action = canManage ? status.action : null;
    const Icon = action ? icons[action] : null;
    const ddUrl = (name: string, params: Record<string, string> = {}) =>
        route(`admin.billing.tenants.direct-debit.${name}`, { company: company.id, ...params });

    return (
        <>
            <BillingStatusCard
                status={status}
                recurringLabel={billing.settings.cycle === 'yearly' ? 'Yearly fee' : 'Monthly fee'}
                action={
                    action && Icon ? (
                        <Button size="sm" variant={status.tone === 'danger' ? 'destructive' : 'default'} onClick={() => setOpen(action)}>
                            <Icon />
                            {status.actionLabel}
                        </Button>
                    ) : undefined
                }
            />

            {canManage && (
                <>
                    <Dialog open={open === 'recordSetupPayment'} onOpenChange={close}>
                        {open === 'recordSetupPayment' && <UpfrontBody company={company} directDebit={directDebit} onOpenChange={close} />}
                    </Dialog>
                    <RecordPaymentDialog
                        open={open === 'recordPayment'}
                        onOpenChange={close}
                        company={company}
                        methods={billing.options.methods}
                        invoices={billing.openInvoices}
                    />
                    <ConfirmDialog
                        open={open === 'sendDirectDebitLink'}
                        onOpenChange={close}
                        title="Send the Direct Debit setup link?"
                        description="The owners get an email with a secure link to the GoCardless page. Once they finish, the business unlocks at once (if suspended) and the subscription starts."
                        confirmLabel="Send link"
                        onConfirm={() => post(ddUrl('setup-email'))}
                    />
                    <ConfirmDialog
                        open={open === 'retryPayment'}
                        onOpenChange={close}
                        title="Retry the failed Direct Debit?"
                        description="GoCardless collects it again in a few working days. The invoice stays unpaid until the money is confirmed; if it fails again the owners are told."
                        confirmLabel="Retry Direct Debit"
                        onConfirm={() => (status.failedPaymentId ? post(ddUrl('retry', { payment: status.failedPaymentId })) : undefined)}
                    />
                </>
            )}
        </>
    );
}

export default TenantBillingStatus;
