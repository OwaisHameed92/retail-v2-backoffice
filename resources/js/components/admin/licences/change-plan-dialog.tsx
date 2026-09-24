import { Field } from '@/components/admin/tenants/field';
import { type PlanOption } from '@/components/admin/licences/types';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

interface ChangePlanDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: ReactNode;
    url: string;
    method: 'post' | 'put';
    plans: PlanOption[];
    currentPlanId: string | null;
    /** Tenant mode: offer to move the current licences too (count shown in the label). */
    applyToLicences?: number;
}

/** Pick an active plan for one licence, or as a tenant's plan for new tills. */
export function ChangePlanDialog(props: ChangePlanDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <ChangePlanBody {...props} />}
        </Dialog>
    );
}

function ChangePlanBody({ onOpenChange, title, description, url, method, plans, currentPlanId, applyToLicences }: ChangePlanDialogProps) {
    const inList = plans.some((plan) => plan.value === currentPlanId);
    const { data, setData, submit, processing, errors } = useForm<{ plan_id: string; apply_to_licences: boolean }>({
        plan_id: inList && currentPlanId ? currentPlanId : (plans[0]?.value ?? ''),
        apply_to_licences: false,
    });
    const chosen = plans.find((plan) => plan.value === data.plan_id);
    const unchanged = data.plan_id === currentPlanId && !data.apply_to_licences;

    const send: FormEventHandler = (event) => {
        event.preventDefault();
        submit(method, url, { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <DialogContent onInteractOutside={(event) => processing && event.preventDefault()}>
            <form onSubmit={send} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                {plans.length === 0 ? (
                    <p className="text-muted-foreground text-sm">There are no active plans. Create or reactivate a plan first.</p>
                ) : (
                    <Field id="plan-id" label="Plan" error={errors.plan_id} hint={chosen?.description ?? undefined}>
                        <Select value={data.plan_id} onValueChange={(value) => setData('plan_id', value)}>
                            <SelectTrigger id="plan-id" aria-invalid={!!errors.plan_id}>
                                <SelectValue placeholder="Choose a plan" />
                            </SelectTrigger>
                            <SelectContent>
                                {plans.map((plan) => (
                                    <SelectItem key={plan.value} value={plan.value}>
                                        {plan.label}
                                        {plan.value === currentPlanId && <span className="text-muted-foreground"> (current)</span>}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                )}

                {applyToLicences !== undefined && applyToLicences > 0 && (
                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="apply-to-licences"
                            checked={data.apply_to_licences}
                            onCheckedChange={(checked) => setData('apply_to_licences', checked === true)}
                            className="mt-0.5"
                        />
                        <div className="grid gap-1">
                            <Label htmlFor="apply-to-licences">
                                Also move the {applyToLicences === 1 ? 'current licence' : `${applyToLicences} current licences`}
                            </Label>
                            <p className="text-muted-foreground text-sm">Their features change at the next check-in. Dates stay the same.</p>
                        </div>
                    </div>
                )}

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing || plans.length === 0 || unchanged}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Change plan
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
