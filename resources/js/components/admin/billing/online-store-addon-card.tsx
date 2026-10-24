import { formatDate } from '@/components/admin/billing/format';
import { type CompanyRef, type OnlineStoreAddOnData } from '@/components/admin/billing/types';
import { MoneyInput } from '@/components/admin/plans/plan-form-fields';
import { Field } from '@/components/admin/tenants/field';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { taxName, taxText } from '@/lib/country';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { ExternalLink, LoaderCircle, Store, TriangleAlert } from 'lucide-react';
import { type FormEventHandler, type ReactNode, useState } from 'react';

interface OnlineStoreAddOnCardProps {
    company: CompanyRef;
    addOn: OnlineStoreAddOnData;
    canManage: boolean;
    cancelled?: boolean;
}

function Item({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid content-start gap-1">
            <span className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{label}</span>
            <div className="text-sm">{children}</div>
        </div>
    );
}

/** Why the add-on cannot be used or billed now (OS-6). */
function unavailableReason(addOn: OnlineStoreAddOnData): string | null {
    if (addOn.available) {
        return null;
    }

    if (!addOn.monthlyPlan) {
        return addOn.on
            ? 'This business is not on a monthly plan any more, so the store is closed and the add-on is not charged. Move it to a monthly plan (Change plan) or switch the add-on off.'
            : 'The online store add-on is only for businesses on a monthly plan. Move the business to a monthly plan first (Change plan).';
    }

    if (!addOn.on) {
        return null;
    }

    if (!addOn.cloudSync) {
        return 'No till of this business has cloud sync (the online dashboard), so the store is closed and the add-on is not charged. Switch the add-on off, or add cloud sync.';
    }

    return 'Online stores are switched off on this server, so the store is closed for now. The add-on is still on the invoice.';
}

/**
 * Online store OS-6, tenant Billing tab: the online store add-on (owner 2026-10-23), switched on per business by us for
 * businesses on a monthly plan, at the default price or the business's own, with the store's template (owner
 * 2026-10-24). Billed as a line on the monthly invoice.
 */
export function OnlineStoreAddOnCard({ company, addOn, canManage, cancelled = false }: OnlineStoreAddOnCardProps) {
    const [open, setOpen] = useState(false);
    const reason = unavailableReason(addOn);

    return (
        <SectionCard
            title="Online store add-on"
            description={
                addOn.byPlan
                    ? `Click & collect online store, included in ${addOn.planName ?? 'its plan'}: ${addOn.defaultPriceLabel} a month before ${taxName()} unless this business has its own price, as a line on the monthly invoice. Change plan switches it with the plan.`
                    : `Click & collect online store for businesses on a monthly plan. ${addOn.defaultPriceLabel} a month before ${taxName()} unless this business has its own price, as a line on the monthly invoice.`
            }
            actions={
                canManage && !cancelled ? (
                    <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
                        <Store />
                        {addOn.on ? 'Change add-on' : 'Switch on'}
                    </Button>
                ) : undefined
            }
        >
            <div className="grid gap-4">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <Item label="Add-on">
                        <span className="inline-flex flex-wrap items-center gap-2">
                            <StatusPill tone={addOn.on ? 'success' : 'neutral'}>{addOn.on ? 'On' : 'Off'}</StatusPill>
                            {addOn.on && addOn.since && <span className="text-muted-foreground">since {formatDate(addOn.since)}</span>}
                        </span>
                    </Item>
                    <Item label="Price">
                        <span className="font-medium tabular-nums">{addOn.priceLabel} a month</span>
                        <span className="text-muted-foreground block">
                            {taxText('Before VAT')}
                            {addOn.priceSource === 'business' ? ' · own price' : addOn.priceSource === 'plan' ? ' · plan price' : ' · default'}
                        </span>
                        {addOn.billed && (
                            <span className="text-muted-foreground block tabular-nums">
                                Invoice line {addOn.cycleGross} {addOn.per} incl. {taxName()}
                            </span>
                        )}
                    </Item>
                    <Item label="Template">{addOn.templateLabel ?? <span className="text-muted-foreground">Not chosen yet</span>}</Item>
                    <Item label="Store">
                        {addOn.slug ? (
                            <span className="inline-flex flex-wrap items-center gap-2">
                                <span className="font-mono text-xs">/{addOn.slug}</span>
                                <StatusPill tone={addOn.live && addOn.available ? 'success' : 'neutral'}>
                                    {addOn.live && addOn.available ? 'Live' : 'Not live'}
                                </StatusPill>
                                {addOn.live && addOn.available && addOn.url && (
                                    <a
                                        href={addOn.url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="text-primary inline-flex items-center gap-1 hover:underline"
                                    >
                                        Open
                                        <ExternalLink className="size-3.5" aria-hidden />
                                    </a>
                                )}
                            </span>
                        ) : (
                            <span className="text-muted-foreground">Made when the add-on is switched on</span>
                        )}
                    </Item>
                </div>
                {reason && (
                    <Alert>
                        <TriangleAlert />
                        <AlertDescription>{reason}</AlertDescription>
                    </Alert>
                )}
            </div>

            {canManage && (
                <Dialog open={open} onOpenChange={setOpen}>
                    {open && <AddOnDialogBody company={company} addOn={addOn} onOpenChange={setOpen} />}
                </Dialog>
            )}
        </SectionCard>
    );
}

function AddOnDialogBody({
    company,
    addOn,
    onOpenChange,
}: {
    company: CompanyRef;
    addOn: OnlineStoreAddOnData;
    onOpenChange: (open: boolean) => void;
}) {
    const { data, setData, put, processing, errors } = useForm({
        on: true as boolean,
        monthly_price: addOn.monthlyPrice ?? '',
        template: addOn.template ?? '',
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        if (processing) {
            return;
        }
        put(route('admin.tenants.online-store.update', company.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    const errorList = errors as Record<string, string | undefined>;

    return (
        <DialogContent className="sm:max-w-lg" onInteractOutside={(event) => processing && event.preventDefault()}>
            <form onSubmit={submit} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>Online store add-on for {company.name}</DialogTitle>
                    <DialogDescription>
                        A line “Online store” on the monthly invoice from the next one, with {taxName()}, collected by the Direct Debit with the plan.
                        Switching it off stops it from the next invoice and closes the store (its settings are kept).
                    </DialogDescription>
                </DialogHeader>

                <div className="flex items-start gap-3">
                    <Checkbox id="addon-on" checked={data.on} onCheckedChange={(checked) => setData('on', checked === true)} className="mt-0.5" />
                    <div className="grid gap-1">
                        <Label htmlFor="addon-on">Online store add-on on</Label>
                        <p className="text-muted-foreground text-sm">The business can build its store and take click & collect orders.</p>
                        {errorList.on && <p className="text-destructive text-sm">{errorList.on}</p>}
                        {addOn.on && !data.on && (addOn.openOrders ?? 0) > 0 && (
                            <p role="alert" className="text-warning-foreground bg-warning-soft rounded-md px-3 py-2 text-sm">
                                {addOn.openOrders} online {addOn.openOrders === 1 ? 'order is' : 'orders are'} still open. Customers cannot place new
                                orders once it is off, but staff can still finish the open ones on the Online orders board.
                            </p>
                        )}
                    </div>
                </div>

                <Field
                    id="addon-price"
                    label="Monthly price"
                    optional
                    error={errors.monthly_price}
                    hint={taxText(`Before VAT. Blank = ${addOn.byPlan ? 'the plan’s price' : 'the default'}, ${addOn.defaultPriceLabel}.`)}
                >
                    <MoneyInput
                        id="addon-price"
                        placeholder={addOn.defaultPrice}
                        value={data.monthly_price}
                        invalid={!!errors.monthly_price}
                        onChange={(event) => setData('monthly_price', event.target.value)}
                    />
                </Field>

                <fieldset className="grid gap-2">
                    <legend className="mb-2 text-sm font-medium">Template</legend>
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2" role="radiogroup" aria-label="Template">
                        {addOn.templates.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                role="radio"
                                aria-checked={data.template === option.value}
                                onClick={() => setData('template', option.value)}
                                className={cn(
                                    'focus-visible:ring-ring rounded-lg border p-3 text-left text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none',
                                    data.template === option.value ? 'border-primary bg-primary/5 text-primary' : 'hover:bg-muted/60',
                                )}
                            >
                                {option.label}
                            </button>
                        ))}
                    </div>
                    <p className="text-muted-foreground text-sm">Chosen by Switch & Save. The business sets its logo, colours and products.</p>
                    {errors.template && <p className="text-destructive text-sm">{errors.template}</p>}
                </fieldset>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Save add-on
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}

export default OnlineStoreAddOnCard;
