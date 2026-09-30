import { formatDate } from '@/components/admin/billing/format';
import { PricingDialog } from '@/components/admin/billing/pricing-dialog';
import { type CompanyRef, type DirectDebitData } from '@/components/admin/billing/types';
import { emptyUpfront, type OnboardingBillingOptions, UpfrontPaymentFields } from '@/components/admin/billing/upfront-payment-fields';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
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

/** Tenant Billing tab (module 1.13): how the business is priced (plan or its own override) and its upfront payment. */
export function PricingPanel({ company, directDebit, canManage }: PricingPanelProps) {
    const [dialog, setDialog] = useState<'pricing' | 'upfront' | null>(null);
    const close = (open: boolean) => !open && setDialog(null);
    const { pricing, upfront, setupFee } = directDebit;

    return (
        <SectionCard
            title="Pricing and upfront payment"
            description="What the business pays each cycle, and what it paid when it joined."
            actions={
                canManage ? (
                    <div className="flex flex-wrap gap-2">
                        {upfront.canRecord && (
                            <Button size="sm" variant="outline" onClick={() => setDialog('upfront')}>
                                <Banknote />
                                Record upfront payment
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
                <Tile label="Pricing">
                    <span className="inline-flex flex-wrap items-center gap-2 font-medium">
                        {pricing.modeLabel}
                        {pricing.overridden && <StatusPill tone="info">Custom</StatusPill>}
                    </span>
                    <span className="text-muted-foreground text-sm tabular-nums">
                        {pricing.unitPrice ? `${pricing.unitPrice} per ${pricing.unit} ${pricing.per}` : 'Mixed plan prices'}
                        {pricing.plan ? ` · ${pricing.plan.name}` : ''}
                    </span>
                </Tile>
                <Tile label="Each cycle">
                    <span className="font-medium tabular-nums">
                        {pricing.recurringIsZero ? 'Nothing to pay' : `${pricing.recurring} ${pricing.per}`}
                    </span>
                    <span className="text-muted-foreground text-sm">
                        {pricing.recurringIsZero ? 'No Direct Debit needed' : `${pricing.unitsLabel}, VAT included`}
                    </span>
                </Tile>
                <Tile label="Upfront payment">
                    <span className="font-medium tabular-nums">{upfront.recorded ? upfront.amount : 'Not recorded'}</span>
                    <span className="text-muted-foreground text-sm">
                        {upfront.recorded && upfront.recordedAt
                            ? `${upfront.method} · ${formatDate(upfront.recordedAt)}`
                            : setupFee.invoicedAt
                              ? 'Setup fee invoiced separately'
                              : 'Setup fee collected by Direct Debit unless recorded here'}
                    </span>
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
    const options: OnboardingBillingOptions = {
        canRecord: true,
        setupFees: {},
        vatRate: directDebit.setupFee.vatRate,
        deadlineDays: 0,
        methods: [
            { value: 'cash', label: 'Cash' },
            { value: 'bankTransfer', label: 'Bank transfer' },
        ],
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(route('admin.billing.tenants.upfront', company.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <DialogContent className="sm:max-w-lg" onInteractOutside={(event) => processing && event.preventDefault()}>
            <form onSubmit={submit} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>Record the upfront payment</DialogTitle>
                    <DialogDescription>
                        {company.name} gets a paid setup fee invoice by email. The setup fee is then never collected by Direct Debit.
                    </DialogDescription>
                </DialogHeader>
                <UpfrontPaymentFields
                    value={data}
                    onChange={(key, value) => setData(key, value as never)}
                    errors={errors}
                    options={options}
                    planFee={directDebit.setupFee.plan}
                    toggle={false}
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
