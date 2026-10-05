import { formatDate, formatDay } from '@/components/admin/billing/format';
import { PricingDialog } from '@/components/admin/billing/pricing-dialog';
import { type CompanyRef, type DirectDebitData, type SetupFeeStatus } from '@/components/admin/billing/types';
import { emptyUpfront, type OnboardingBillingOptions, UpfrontPaymentFields } from '@/components/admin/billing/upfront-payment-fields';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill, type StatusTone } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useForm } from '@inertiajs/react';
import { Banknote, LoaderCircle, Tags } from 'lucide-react';
import { type FormEventHandler, type ReactNode, useState } from 'react';

function Tile({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid content-start gap-1.5 rounded-lg border p-4">
            <span className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{label}</span>
            {children}
        </div>
    );
}

interface PricingPanelProps {
    company: CompanyRef;
    directDebit: DirectDebitData;
    canManage: boolean;
}

const feeTones: Record<SetupFeeStatus, StatusTone> = { none: 'neutral', unpaid: 'warning', partPaid: 'info', paid: 'success' };

/**
 * Tenant Billing tab (owner rules 2026-10-05): the plan type and pricing, the setup fee (upfront, always paid by hand)
 * and the monthly or yearly fee (always by Direct Debit) with the mandate and the next collection.
 */
export function PricingPanel({ company, directDebit, canManage }: PricingPanelProps) {
    const [dialog, setDialog] = useState<'pricing' | 'upfront' | null>(null);
    const close = (open: boolean) => !open && setDialog(null);
    const { pricing, upfront, mandate, subscription, planType } = directDebit;
    const cycleLabel = pricing.per === 'per year' ? 'Yearly' : 'Monthly';
    const byDirectDebit = directDebit.mode === 'directDebit';

    return (
        <SectionCard
            title="Plan and payments"
            description="The setup fee is paid by hand (cash, card or bank transfer). The monthly or yearly fee is collected by Direct Debit."
            actions={
                canManage ? (
                    <div className="flex flex-wrap gap-2">
                        {upfront.canRecord && (
                            <Button size="sm" variant="outline" onClick={() => setDialog('upfront')}>
                                <Banknote />
                                Record setup fee payment
                            </Button>
                        )}
                        <Button size="sm" variant="outline" onClick={() => setDialog('pricing')}>
                            <Tags />
                            Change pricing
                        </Button>
                    </div>
                ) : undefined
            }
        >
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <Tile label="Plan">
                    <span className="inline-flex flex-wrap items-center gap-2 font-medium">
                        {pricing.plan?.name ?? 'No plan'}
                        {pricing.overridden && <StatusPill tone="info">Custom pricing</StatusPill>}
                    </span>
                    <span className="text-muted-foreground text-sm">{planType?.label ?? pricing.modeLabel}</span>
                    {!pricing.recurringIsZero && (
                        <span className="text-muted-foreground text-sm tabular-nums">
                            {pricing.unitPrice ? `${pricing.unitPrice} per ${pricing.unit} ${pricing.per}` : 'Mixed plan prices'}
                        </span>
                    )}
                </Tile>
                <Tile label="Setup fee (upfront)">
                    <span className="inline-flex flex-wrap items-center gap-2 font-medium tabular-nums">
                        {upfront.status === 'none' ? (upfront.recorded ? upfront.amount : '£0.00') : upfront.total}
                        <StatusPill tone={feeTones[upfront.status]}>{upfront.statusLabel}</StatusPill>
                    </span>
                    <span className="text-muted-foreground text-sm">
                        {upfront.status === 'paid' || (upfront.status === 'none' && upfront.recorded)
                            ? `${upfront.method ?? 'Recorded'}${upfront.recordedAt ? ` · ${formatDate(upfront.recordedAt)}` : ''}`
                            : upfront.status === 'none'
                              ? 'Nothing to pay'
                              : `${upfront.owed} to pay by cash, card or bank transfer${upfront.nextDue ? ` · next due ${formatDay(upfront.nextDue)}` : ''}`}
                    </span>
                </Tile>
                <Tile label={`${cycleLabel} fee`}>
                    <span className="font-medium tabular-nums">
                        {pricing.recurringIsZero ? 'Nothing recurring' : `${pricing.recurring} ${byDirectDebit ? 'by Direct Debit' : pricing.per}`}
                    </span>
                    {pricing.recurringIsZero ? (
                        <span className="text-muted-foreground text-sm">No Direct Debit needed</span>
                    ) : byDirectDebit ? (
                        <>
                            <span className="inline-flex flex-wrap items-center gap-2 text-sm">
                                Mandate
                                <StatusPill tone={mandate.usable ? 'success' : mandate.lostAt ? 'danger' : 'warning'}>
                                    {mandate.statusLabel}
                                </StatusPill>
                            </span>
                            <span className="text-muted-foreground text-sm">
                                {subscription.live && subscription.nextChargeDate
                                    ? `Next collection ${formatDay(subscription.nextChargeDate)} · ${subscription.amount ?? pricing.recurring}`
                                    : upfront.status === 'unpaid'
                                      ? 'Starts once the setup fee is paid'
                                      : mandate.usable
                                        ? 'Subscription not running'
                                        : 'No collection until the mandate is set up'}
                            </span>
                        </>
                    ) : (
                        <span className="text-muted-foreground text-sm">Paid by hand (exception), {pricing.unitsLabel}</span>
                    )}
                </Tile>
            </div>

            {canManage && (
                <>
                    <PricingDialog open={dialog === 'pricing'} onOpenChange={close} company={company} pricing={pricing} />
                    <Dialog open={dialog === 'upfront'} onOpenChange={close}>
                        {dialog === 'upfront' && <UpfrontBody company={company} directDebit={directDebit} onOpenChange={close} />}
                    </Dialog>
                </>
            )}
        </SectionCard>
    );
}

function UpfrontBody({
    company,
    directDebit,
    onOpenChange,
}: {
    company: CompanyRef;
    directDebit: DirectDebitData;
    onOpenChange: (open: boolean) => void;
}) {
    const { data, setData, post, processing, errors } = useForm({ ...emptyUpfront, upfront_record: true });
    const { upfront } = directDebit;
    const options: OnboardingBillingOptions = {
        canRecord: true,
        setupFees: {},
        vatRate: directDebit.setupFee.vatRate,
        deadlineDays: 0,
        methods: upfront.methods,
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('admin.billing.tenants.upfront', company.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <DialogContent className="sm:max-w-lg" onInteractOutside={(event) => processing && event.preventDefault()}>
            <form onSubmit={submit} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>Record a setup fee payment</DialogTitle>
                    <DialogDescription>
                        {upfront.invoiced
                            ? `Pays the next unpaid part of ${company.name}'s setup fee (${upfront.owed} still to pay). The paid invoice is emailed as the receipt.`
                            : `${company.name} gets a paid setup fee invoice by email as the receipt.`}{' '}
                        The setup fee is never taken by Direct Debit.
                    </DialogDescription>
                </DialogHeader>
                <UpfrontPaymentFields
                    value={data}
                    onChange={(key, value) => setData(key, value as never)}
                    errors={errors}
                    options={options}
                    planFee={directDebit.setupFee.plan}
                    toggle={false}
                    amountLocked={upfront.invoiced}
                    idPrefix="tab-upfront"
                />
                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Record payment
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

export default PricingPanel;
