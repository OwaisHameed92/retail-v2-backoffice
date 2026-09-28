import { LicenceFormFields, licencePayload, licenceValues, type LicenceFormValues } from '@/components/admin/licences/licence-form-fields';
import { type LicenceOptions } from '@/components/admin/licences/types';
import { type TenantBranch } from '@/components/admin/tenants/types';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useForm } from '@inertiajs/react';
import { Info, LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface BranchLicenceDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    tenantId: string;
    branch: TenantBranch;
    options: LicenceOptions;
}

/** A branch's licence settings (module 1.11). Saving re-signs every till key of the branch at its next check-in. */
export function BranchLicenceDialog(props: BranchLicenceDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <BranchLicenceDialogBody {...props} />}
        </Dialog>
    );
}

function BranchLicenceDialogBody({ onOpenChange, tenantId, branch, options }: BranchLicenceDialogProps) {
    const { data, setData, put, processing, errors, transform, isDirty } = useForm<LicenceFormValues>(licenceValues(branch.licence));

    transform((values) => licencePayload(values) as unknown as LicenceFormValues);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('admin.tenants.branches.licence', [tenantId, branch.id]), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl" onInteractOutside={(e) => processing && e.preventDefault()}>
            <form onSubmit={submit} className="grid gap-6" noValidate>
                <DialogHeader>
                    <DialogTitle>Licence settings for {branch.name}</DialogTitle>
                    <DialogDescription>
                        What every till key of this branch carries. {branch.licence.keysInUse} of {branch.licence.maxRegisters} keys in use,{' '}
                        {branch.licence.keysActivated} activated.
                    </DialogDescription>
                </DialogHeader>

                <LicenceFormFields
                    values={data}
                    setValue={(key, value) => setData(key, value as never)}
                    errors={errors}
                    options={options}
                    tillsInUse={branch.licence.keysInUse}
                />

                <Alert variant="info">
                    <Info className="size-4" />
                    <AlertDescription>
                        Each till gets the new key details at its next check-in. A new kind, length or start also sets the dates of every key of the
                        branch again, replacing renewals.
                    </AlertDescription>
                </Alert>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing || !isDirty}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Save settings
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
