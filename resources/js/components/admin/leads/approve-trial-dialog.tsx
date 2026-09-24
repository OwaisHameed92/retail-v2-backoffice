import { type Nation } from '@/components/admin/tenants/types';
import { FormField } from '@/components/shared/form-section';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { Link, useForm } from '@inertiajs/react';
import { Building2, CircleAlert, KeyRound, Mail, Minus, MonitorSmartphone, Plus, Store, Trash2, UserRound, type LucideIcon } from 'lucide-react';
import { useEffect, useState, type FormEventHandler, type ReactNode } from 'react';
import { DialogForm } from './dialog-form';
import { plural, suggestBranchCode } from './format';
import { type ApprovalData, type LeadDetail, type TrialShopInput } from './types';

interface ApproveTrialDialogProps {
    lead: LeadDetail;
    approval: ApprovalData;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

type ApproveForm = { shops: TrialShopInput[]; plan_id: string };

/** Recomputes the codes nobody typed by hand, so they follow the names and stay unique. */
function withSuggestedCodes(shops: TrialShopInput[], edited: boolean[]): TrialShopInput[] {
    const taken = shops.filter((_, index) => edited[index]).map((shop) => shop.code);

    return shops.map((shop, index) => {
        if (edited[index]) {
            return shop;
        }
        const code = suggestBranchCode(shop.name, taken);
        taken.push(code);

        return { ...shop, code };
    });
}

function Outcome({ icon: Icon, children }: { icon: LucideIcon; children: ReactNode }) {
    return (
        <li className="flex gap-3">
            <span className="bg-primary-soft text-primary mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full">
                <Icon className="size-3.5" aria-hidden />
            </span>
            <span className="min-w-0 text-sm leading-6">{children}</span>
        </li>
    );
}

/**
 * "Approve 7-day trial": shows exactly what will be created (branches and tills, owner login, plan, emails) and
 * lets staff adjust the shops before confirming. Posts to LeadApprovalController; the server re-checks it all.
 */
export function ApproveTrialDialog({ lead, approval, open, onOpenChange }: ApproveTrialDialogProps) {
    const initial: ApproveForm = {
        shops: approval.suggestion.shops,
        plan_id: approval.suggestion.planId ?? approval.defaultPlanId ?? '',
    };
    const form = useForm<ApproveForm>(initial);
    const [edited, setEdited] = useState<boolean[]>(() => approval.suggestion.shops.map(() => false));
    const errors = form.errors as Record<string, string | undefined>;

    useEffect(() => {
        if (open) {
            form.setData(initial);
            form.clearErrors();
            setEdited(approval.suggestion.shops.map(() => false));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const shops = form.data.shops;
    const totalTills = shops.reduce((sum, shop) => sum + (Number.isFinite(shop.tills) ? shop.tills : 0), 0);
    const plan = approval.plans.find((option) => option.value === form.data.plan_id);
    const trialDays = approval.plansTrialDays[form.data.plan_id] ?? approval.trialDays;
    const blocked = !lead.email || approval.plans.length === 0;

    const updateShop = (index: number, patch: Partial<TrialShopInput>, codeTyped = false) => {
        const nextEdited = codeTyped ? edited.map((value, i) => (i === index ? true : value)) : edited;
        const next = shops.map((shop, i) => (i === index ? { ...shop, ...patch } : shop));
        setEdited(nextEdited);
        form.setData('shops', patch.name !== undefined ? withSuggestedCodes(next, nextEdited) : next);
    };

    const addShop = () => {
        const next = [...shops, { name: `Shop ${shops.length + 1}`, code: '', tills: 1, nation: shops[0]?.nation ?? 'england' }];
        const nextEdited = [...edited, false];
        setEdited(nextEdited);
        form.setData('shops', withSuggestedCodes(next, nextEdited));
    };

    const removeShop = (index: number) => {
        const nextEdited = edited.filter((_, i) => i !== index);
        setEdited(nextEdited);
        form.setData(
            'shops',
            withSuggestedCodes(
                shops.filter((_, i) => i !== index),
                nextEdited,
            ),
        );
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('admin.leads.approve', lead.id), { preserveScroll: true });
    };

    const generalError = errors.status ?? errors.email ?? errors.shops ?? errors.plan_id;

    return (
        <DialogForm
            open={open}
            onOpenChange={onOpenChange}
            title={`Approve a ${trialDays}-day trial for ${lead.businessName}`}
            description="Check what will be created. Nothing happens until you confirm."
            submitLabel="Approve and create tenant"
            processing={form.processing}
            onSubmit={submit}
            disabled={blocked || shops.length === 0}
            className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-3xl"
        >
            {!lead.email && (
                <Alert variant="destructive">
                    <CircleAlert className="size-4" />
                    <AlertTitle>Add the contact’s email first</AlertTitle>
                    <AlertDescription>
                        The owner signs in with it and gets the licence keys by email.{' '}
                        <Link href={route('admin.leads.edit', lead.id)} className="font-medium underline underline-offset-2">
                            Edit the lead
                        </Link>
                    </AlertDescription>
                </Alert>
            )}
            {approval.plans.length === 0 && (
                <Alert variant="warning">
                    <CircleAlert className="size-4" />
                    <AlertTitle>No active plan</AlertTitle>
                    <AlertDescription>The tills would have no licence keys. Create a plan first.</AlertDescription>
                </Alert>
            )}
            {generalError && (
                <Alert variant="destructive">
                    <CircleAlert className="size-4" />
                    <AlertDescription>{generalError}</AlertDescription>
                </Alert>
            )}

            <section aria-labelledby="approve-shops" className="grid gap-3">
                <div className="flex items-end justify-between gap-3">
                    <div>
                        <h3 id="approve-shops" className="text-sm font-semibold">
                            Shops and tills
                        </h3>
                        <p className="text-muted-foreground text-sm">
                            They asked for {plural(lead.shopsCount, 'shop')} and {plural(lead.tillsCount, 'till')}. Each shop becomes a branch; its
                            first till is the main till.
                        </p>
                    </div>
                </div>

                <div className="hidden grid-cols-[minmax(0,1fr)_6rem_9.5rem_8.5rem_2.25rem] gap-2 px-1 sm:grid" aria-hidden>
                    {['Shop name', 'Code', 'Nation', 'Tills', ''].map((label) => (
                        <span key={label} className="text-muted-foreground text-xs font-medium">
                            {label}
                        </span>
                    ))}
                </div>

                <ol className="grid gap-3 sm:gap-2">
                    {shops.map((shop, index) => (
                        <li
                            key={index}
                            className="grid gap-2 rounded-lg border p-3 sm:grid-cols-[minmax(0,1fr)_6rem_9.5rem_8.5rem_2.25rem] sm:items-start sm:border-0 sm:p-0"
                        >
                            <FormField
                                id={`shop-${index}-name`}
                                label={<span className="sm:sr-only">Shop {index + 1} name</span>}
                                error={errors[`shops.${index}.name`]}
                            >
                                <Input
                                    id={`shop-${index}-name`}
                                    value={shop.name}
                                    maxLength={120}
                                    onChange={(event) => updateShop(index, { name: event.target.value })}
                                    aria-invalid={!!errors[`shops.${index}.name`]}
                                />
                            </FormField>
                            <FormField
                                id={`shop-${index}-code`}
                                label={<span className="sm:sr-only">Code</span>}
                                error={errors[`shops.${index}.code`]}
                            >
                                <Input
                                    id={`shop-${index}-code`}
                                    value={shop.code}
                                    maxLength={5}
                                    onChange={(event) => updateShop(index, { code: event.target.value.toUpperCase().replace(/[^A-Z]/g, '') }, true)}
                                    className="font-mono uppercase"
                                    aria-invalid={!!errors[`shops.${index}.code`]}
                                    aria-describedby={`shop-${index}-code-help`}
                                />
                                <span id={`shop-${index}-code-help`} className="sr-only">
                                    2 to 5 capital letters, used in receipt numbers.
                                </span>
                            </FormField>
                            <FormField id={`shop-${index}-nation`} label={<span className="sm:sr-only">Nation</span>}>
                                <Select value={shop.nation} onValueChange={(value) => updateShop(index, { nation: value as Nation })}>
                                    <SelectTrigger id={`shop-${index}-nation`}>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {approval.nations.map((nation) => (
                                            <SelectItem key={nation.value} value={nation.value}>
                                                {nation.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <FormField
                                id={`shop-${index}-tills`}
                                label={<span className="sm:sr-only">Tills</span>}
                                error={errors[`shops.${index}.tills`]}
                            >
                                <div className="flex items-center gap-1">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        className="size-9 shrink-0"
                                        disabled={shop.tills <= 1}
                                        onClick={() => updateShop(index, { tills: Math.max(1, shop.tills - 1) })}
                                        aria-label={`One fewer till at ${shop.name || `shop ${index + 1}`}`}
                                    >
                                        <Minus />
                                    </Button>
                                    <Input
                                        id={`shop-${index}-tills`}
                                        type="number"
                                        inputMode="numeric"
                                        min={1}
                                        max={approval.maxTillsPerShop}
                                        value={shop.tills}
                                        onChange={(event) =>
                                            updateShop(index, {
                                                tills: Math.min(approval.maxTillsPerShop, Math.max(1, Math.round(Number(event.target.value) || 1))),
                                            })
                                        }
                                        className="w-12 px-1 text-center tabular-nums"
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        className="size-9 shrink-0"
                                        disabled={shop.tills >= approval.maxTillsPerShop}
                                        onClick={() => updateShop(index, { tills: Math.min(approval.maxTillsPerShop, shop.tills + 1) })}
                                        aria-label={`One more till at ${shop.name || `shop ${index + 1}`}`}
                                    >
                                        <Plus />
                                    </Button>
                                </div>
                            </FormField>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className={cn(
                                    'text-muted-foreground hover:text-destructive size-9 justify-self-end',
                                    shops.length === 1 && 'invisible',
                                )}
                                disabled={shops.length === 1}
                                onClick={() => removeShop(index)}
                                aria-label={`Remove ${shop.name || `shop ${index + 1}`}`}
                            >
                                <Trash2 />
                            </Button>
                        </li>
                    ))}
                </ol>

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Button type="button" variant="outline" size="sm" onClick={addShop} disabled={shops.length >= approval.maxShops}>
                        <Plus />
                        Add shop
                    </Button>
                    <p className="text-muted-foreground text-sm tabular-nums">
                        {plural(shops.length, 'shop')} · {plural(totalTills, 'till')}
                    </p>
                </div>
            </section>

            {approval.plans.length > 0 && (
                <FormField id="approve-plan" label="Plan" help={plan?.description ?? undefined} error={errors.plan_id}>
                    <Select value={form.data.plan_id} onValueChange={(value) => form.setData('plan_id', value)}>
                        <SelectTrigger id="approve-plan" className="sm:max-w-xs">
                            <SelectValue placeholder="Choose a plan" />
                        </SelectTrigger>
                        <SelectContent>
                            {approval.plans.map((option) => (
                                <SelectItem key={option.value} value={option.value}>
                                    {option.label}
                                    {option.value === approval.defaultPlanId && <span className="text-muted-foreground ml-1">(default)</span>}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </FormField>
            )}

            <section aria-labelledby="approve-outcome" className="bg-subtle grid gap-3 rounded-xl border p-4">
                <h3 id="approve-outcome" className="text-sm font-semibold">
                    When you confirm
                </h3>
                <ul className="grid gap-2.5">
                    <Outcome icon={Building2}>
                        <strong className="font-medium">{lead.businessName}</strong> is created as a trial customer on{' '}
                        <strong className="font-medium">{plan?.label ?? 'the default plan'}</strong>. The {trialDays}-day trial starts when the first
                        till is activated.
                    </Outcome>
                    <Outcome icon={Store}>
                        {plural(shops.length, 'branch', 'branches')}:{' '}
                        {shops.map((shop, index) => (
                            <span key={index}>
                                {index > 0 && ', '}
                                {shop.name || 'Unnamed'} <span className="text-muted-foreground font-mono text-xs">{shop.code || '—'}</span> (
                                {plural(shop.tills, 'till')})
                            </span>
                        ))}
                        .
                    </Outcome>
                    <Outcome icon={KeyRound}>{plural(totalTills, 'licence key')}, one for each till.</Outcome>
                    <Outcome icon={UserRound}>
                        {approval.ownerHasLogin ? (
                            <>
                                <strong className="font-medium">{lead.email}</strong> already has a portal login: {lead.contactName} is added as the
                                owner and keeps their password.
                            </>
                        ) : (
                            <>
                                A portal login for <strong className="font-medium">{lead.email ?? 'the contact'}</strong> with {lead.contactName} as
                                the owner.
                            </>
                        )}
                    </Outcome>
                    <Outcome icon={Mail}>
                        {approval.ownerHasLogin ? 'One email' : 'Two emails'} to {lead.contactName}: the welcome email with the{' '}
                        {plural(totalTills, 'licence key')}
                        {approval.ownerHasLogin ? '.' : ', and a “set your password” link (valid for 7 days).'}
                    </Outcome>
                    <Outcome icon={MonitorSmartphone}>The lead is marked as converted and linked to the new tenant.</Outcome>
                </ul>
            </section>
        </DialogForm>
    );
}
