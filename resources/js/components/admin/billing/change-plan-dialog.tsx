import { ChangePlanPreview } from '@/components/admin/billing/change-plan-preview';
import { type CompanyRef, type PlanChangeOptions, type PlanChangePreviewData } from '@/components/admin/billing/types';
import { MoneyInput } from '@/components/admin/plans/plan-form-fields';
import { Field } from '@/components/admin/tenants/field';
import { StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { formatMoney } from '@/lib/country';
import { sendJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { ArrowLeft, Check, LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

interface ChangePlanDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    company: CompanyRef;
    options: PlanChangeOptions;
}

/**
 * Admin → business → Billing → "Change plan" (owner 2026-10-07): pick an active plan, read the preview (what happens to
 * features, the setup fee, the monthly fee and its collection, the licences, the invoices made and the email), adjust
 * the setup fee if one is charged, then confirm. Nothing changes until "Confirm change".
 */
export function ChangePlanDialog(props: ChangePlanDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <ChangePlanBody {...props} />}
        </Dialog>
    );
}

function ChangePlanBody({ onOpenChange, company, options }: ChangePlanDialogProps) {
    const [planId, setPlanId] = useState<string | null>(null);
    const [step, setStep] = useState<'choose' | 'preview'>('choose');
    const [setupFee, setSetupFee] = useState('');
    const [preview, setPreview] = useState<PlanChangePreviewData | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [feeError, setFeeError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (step !== 'preview' || planId === null) {
            return;
        }
        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setLoading(true);
            const query = new URLSearchParams({ plan_id: planId });
            if (setupFee.trim() !== '') {
                query.set('setup_fee', setupFee.trim());
            }
            try {
                const result = await sendJson<PlanChangePreviewData>('GET', `${route('admin.tenants.plan-change.preview', company.id)}?${query}`, undefined, controller.signal);
                setLoading(false);
                if (result.ok && result.data) {
                    setPreview(result.data);
                    setError(null);
                    setFeeError(null);
                } else if (result.errors.setup_fee) {
                    setFeeError(result.errors.setup_fee);
                } else {
                    setError(Object.values(result.errors)[0] ?? result.message);
                }
            } catch {
                // Superseded by a newer preview.
            }
        }, 300);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [company.id, planId, setupFee, step]);

    const choose = (id: string) => {
        setPlanId(id);
        setSetupFee('');
        setPreview(null);
        setError(null);
        setFeeError(null);
    };

    const confirm = () => {
        if (planId === null) {
            return;
        }
        setSaving(true);
        router.post(
            route('admin.tenants.plan-change.store', company.id),
            { plan_id: planId, setup_fee: setupFee.trim() === '' ? null : setupFee.trim() },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onError: (errors) => {
                    setFeeError(errors.setup_fee ?? null);
                    setError(errors.plan_id ?? (errors.setup_fee ? null : (Object.values(errors)[0] ?? null)));
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    const selected = options.plans.find((plan) => plan.id === planId) ?? null;
    const ready = preview !== null && preview.to.id === planId && !preview.blocked && !loading && feeError === null;

    return (
        <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl" onInteractOutside={(event) => saving && event.preventDefault()}>
            <DialogHeader>
                <DialogTitle>{step === 'choose' ? `Change plan for ${company.name}` : `Move ${company.name} to ${selected?.name ?? 'the new plan'}`}</DialogTitle>
                <DialogDescription>
                    {step === 'choose'
                        ? 'Choose the new plan. You see exactly what will happen before anything changes.'
                        : 'Check what will happen. Nothing changes until you confirm.'}
                </DialogDescription>
            </DialogHeader>

            {step === 'choose' ? (
                <div role="radiogroup" aria-label="Plans" className="grid gap-2">
                    {options.plans.map((plan) => (
                        <button
                            key={plan.id}
                            type="button"
                            role="radio"
                            aria-checked={planId === plan.id}
                            disabled={plan.current}
                            onClick={() => choose(plan.id)}
                            className={cn(
                                'focus-visible:ring-ring/50 grid gap-1 rounded-lg border p-3 text-left transition-colors outline-none focus-visible:ring-[3px]',
                                planId === plan.id ? 'border-primary bg-primary/5' : 'hover:bg-muted/50',
                                plan.current && 'cursor-not-allowed opacity-70',
                            )}
                        >
                            <span className="flex flex-wrap items-center gap-2 font-medium">
                                {planId === plan.id && <Check className="text-primary size-4" aria-hidden />}
                                {plan.name}
                                <span className="text-muted-foreground text-sm font-normal">{plan.typeLabel}</span>
                                {plan.current && <StatusPill tone="neutral">Current plan</StatusPill>}
                            </span>
                            <span className="text-muted-foreground text-sm tabular-nums">
                                {[plan.setupFee ? `Setup fee ${plan.setupFee}` : 'No setup fee', plan.monthly ?? plan.yearly ?? 'Nothing recurring'].join(' · ')}
                            </span>
                            <span className="text-muted-foreground text-xs" title={plan.features.join(', ')}>
                                {plan.features.length === 0
                                    ? 'No features'
                                    : plan.features.length <= 4
                                      ? plan.features.join(', ')
                                      : `${plan.features.slice(0, 3).join(', ')} and ${plan.features.length - 3} more`}
                            </span>
                        </button>
                    ))}
                    {options.plans.length <= 1 && <p className="text-muted-foreground text-sm">No other active plan. Add one under Plans first.</p>}
                </div>
            ) : (
                <div className="grid gap-5" aria-busy={loading}>
                    {preview?.setupFee.applies && (
                        <Field
                            id="plan-change-setup-fee"
                            label="Setup fee to charge"
                            error={feeError ?? undefined}
                            hint={`Suggested ${formatMoney(preview.setupFee.suggested ?? 0)}${preview.setupFee.vatRate ? `, before ${preview.setupFee.taxName}` : ''}. Type 0 to waive it.`}
                        >
                            <MoneyInput
                                id="plan-change-setup-fee"
                                placeholder={preview.setupFee.suggested ?? '0.00'}
                                value={setupFee}
                                invalid={feeError !== null}
                                onChange={(event) => setSetupFee(event.target.value)}
                            />
                        </Field>
                    )}
                    {preview === null ? (
                        error ? (
                            <p className="text-danger-foreground text-sm">{error}</p>
                        ) : (
                            <div className="grid gap-2">
                                <Skeleton className="h-4 w-full" />
                                <Skeleton className="h-4 w-5/6" />
                                <Skeleton className="h-4 w-2/3" />
                            </div>
                        )
                    ) : (
                        <div className={cn('transition-opacity', loading && 'opacity-60')}>
                            {error && <p className="text-danger-foreground mb-3 text-sm">{error}</p>}
                            <ChangePlanPreview preview={preview} />
                        </div>
                    )}
                </div>
            )}

            <DialogFooter className="gap-2">
                {step === 'preview' ? (
                    <Button type="button" variant="outline" onClick={() => setStep('choose')} disabled={saving}>
                        <ArrowLeft />
                        Back
                    </Button>
                ) : (
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                        Cancel
                    </Button>
                )}
                {step === 'choose' ? (
                    <Button type="button" disabled={planId === null} onClick={() => setStep('preview')}>
                        See what changes
                    </Button>
                ) : (
                    <Button type="button" disabled={!ready || saving} onClick={confirm}>
                        {saving && <LoaderCircle className="size-4 animate-spin" />}
                        Confirm change
                    </Button>
                )}
            </DialogFooter>
        </DialogContent>
    );
}

export default ChangePlanDialog;
