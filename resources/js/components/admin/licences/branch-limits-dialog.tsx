import { type BranchLimits } from '@/components/admin/licences/types';
import { FormField } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface BranchLimitsDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    tenantId: string;
    tenantName: string;
    limits: BranchLimits;
    maxBranches: number;
}

/** Multi-branch and branches allowed for the whole business (module 1.11). */
export function BranchLimitsDialog(props: BranchLimitsDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <BranchLimitsDialogBody {...props} />}
        </Dialog>
    );
}

function BranchLimitsDialogBody({ onOpenChange, tenantId, tenantName, limits, maxBranches }: BranchLimitsDialogProps) {
    const { data, setData, put, processing, errors, isDirty } = useForm({
        multi_branch: limits.multiBranch,
        max_branches: Math.max(limits.maxBranches, limits.branchesInUse),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('admin.tenants.branch-limits', tenantId), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <DialogContent className="sm:max-w-lg" onInteractOutside={(e) => processing && e.preventDefault()}>
            <form onSubmit={submit} className="grid gap-6" noValidate>
                <DialogHeader>
                    <DialogTitle>Branches for {tenantName}</DialogTitle>
                    <DialogDescription>
                        {limits.branchesInUse} of {limits.branchesAllowed} {limits.branchesAllowed === 1 ? 'branch' : 'branches'} in use. Every till
                        key of the business carries these at its next check-in.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex items-start gap-3">
                    <Checkbox
                        id="multi_branch"
                        checked={data.multi_branch}
                        onCheckedChange={(checked) => setData('multi_branch', checked === true)}
                        className="mt-0.5"
                        aria-invalid={!!errors.multi_branch}
                    />
                    <div className="grid gap-1">
                        <Label htmlFor="multi_branch">Multi-branch</Label>
                        <p className="text-muted-foreground text-sm">The business may run more than one shop (till feature multi_branch).</p>
                        {errors.multi_branch && <p className="text-danger-foreground text-[13px]">{errors.multi_branch}</p>}
                    </div>
                </div>

                {data.multi_branch && (
                    <FormField
                        id="max_branches"
                        label="Branches allowed"
                        help={`At least the ${limits.branchesInUse} active ${limits.branchesInUse === 1 ? 'branch' : 'branches'}.`}
                        error={errors.max_branches}
                    >
                        <Input
                            id="max_branches"
                            type="number"
                            inputMode="numeric"
                            min={Math.max(1, limits.branchesInUse)}
                            max={maxBranches}
                            value={data.max_branches}
                            onChange={(e) => setData('max_branches', Number.parseInt(e.target.value || '0', 10))}
                            className="max-w-32 tabular-nums"
                            aria-invalid={!!errors.max_branches}
                        />
                    </FormField>
                )}

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing || !isDirty}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Save
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
