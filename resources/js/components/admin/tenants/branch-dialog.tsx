import { BranchFields, type BranchFieldsData } from '@/components/admin/tenants/branch-fields';
import { TillCountPicker } from '@/components/admin/tenants/till-count-picker';
import { type Nation, type Option, type TenantBranch } from '@/components/admin/tenants/types';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface BranchDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    tenantId: string;
    /** Edit this branch; omit to add a new one. */
    branch?: TenantBranch | null;
    nations: Option<Nation>[];
    maxTills: number;
}

type BranchForm = BranchFieldsData & { tills: number };

function initial(branch?: TenantBranch | null): BranchForm {
    return {
        code: branch?.code ?? '',
        name: branch?.name ?? '',
        nation: branch?.nation ?? 'england',
        address: branch?.address ?? '',
        phone: branch?.phone ?? '',
        vat_number: branch?.vatNumber ?? '',
        area_m2: branch?.areaM2 ?? '',
        is_drs_return_point: branch?.isDrsReturnPoint ?? false,
        licensed_hours_json: branch?.licensedHoursJson ?? '',
        tills: 1,
    };
}

/** Add or edit a branch. New branches can get their first tills straight away. */
export function BranchDialog(props: BranchDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <BranchDialogBody key={props.branch?.id ?? 'new'} {...props} />}
        </Dialog>
    );
}

function BranchDialogBody({ onOpenChange, tenantId, branch, nations, maxTills }: BranchDialogProps) {
    const { data, setData, post, put, processing, errors } = useForm<BranchForm>(initial(branch));
    const editing = Boolean(branch);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onOpenChange(false) };

        if (branch) {
            put(route('admin.tenants.branches.update', [tenantId, branch.id]), options);
        } else {
            post(route('admin.tenants.branches.store', tenantId), options);
        }
    };

    return (
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl" onInteractOutside={(e) => processing && e.preventDefault()}>
            <form onSubmit={submit} className="grid gap-6" noValidate>
                <DialogHeader>
                    <DialogTitle>{editing ? `Edit ${branch?.name}` : 'Add branch'}</DialogTitle>
                    <DialogDescription>
                        {editing ? 'Changes reach the till on its next sync.' : 'A new shop for this business. Its details are sent to the tills.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-5 sm:grid-cols-2">
                    <BranchFields
                        data={data}
                        setField={(key, value) => setData(key, value as never)}
                        errors={errors}
                        nations={nations}
                        showLicensedHours={editing}
                    />
                </div>

                {!editing && (
                    <div className="border-t pt-5">
                        <TillCountPicker
                            id="branch-tills"
                            value={data.tills}
                            min={0}
                            max={maxTills}
                            onChange={(value) => setData('tills', value)}
                            error={errors.tills}
                        />
                    </div>
                )}

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        {editing ? 'Save changes' : 'Add branch'}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
