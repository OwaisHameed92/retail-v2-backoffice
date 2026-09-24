import { revealLicenceKeys } from '@/components/admin/licences/reveal-keys';
import { type IssuedKeysReply } from '@/components/admin/licences/types';
import { BranchFields, type BranchFieldsData } from '@/components/admin/tenants/branch-fields';
import { TillCountPicker } from '@/components/admin/tenants/till-count-picker';
import { type Nation, type Option, type TenantBranch } from '@/components/admin/tenants/types';
import { showToast } from '@/components/shared/toaster';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { sendJson } from '@/lib/http';
import { router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

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
    const { data, setData, put, processing: saving, errors, setError, clearErrors } = useForm<BranchForm>(initial(branch));
    const [adding, setAdding] = useState(false);
    const processing = saving || adding;
    const editing = Boolean(branch);

    // New tills get their licences straight away: the reply carries the keys, shown once in the key dialog.
    const add = async () => {
        setAdding(true);
        clearErrors();
        const result = await sendJson<IssuedKeysReply>('POST', route('admin.tenants.branches.store', tenantId), data);
        setAdding(false);

        if (!result.ok || !result.data) {
            const fields = Object.keys(initial(null));
            const formErrors = Object.fromEntries(Object.entries(result.errors).filter(([key]) => fields.includes(key)));
            setError(formErrors as Record<keyof BranchForm, string>);
            if (Object.keys(formErrors).length === 0) {
                showToast(Object.values(result.errors)[0] ?? result.message ?? 'The branch could not be added.', 'error');
            }

            return;
        }

        onOpenChange(false);
        showToast(result.data.message, data.tills > 0 && result.data.keys.length === 0 ? 'error' : 'success');
        revealLicenceKeys({
            keys: result.data.keys,
            title: `${data.name || 'Branch'} added: ${result.data.keys.length === 1 ? 'licence key' : 'licence keys'} created`,
        });
        router.reload({ only: ['branches', 'stats', 'licensing', 'activity'] });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (branch) {
            put(route('admin.tenants.branches.update', [tenantId, branch.id]), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
        } else {
            void add();
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
