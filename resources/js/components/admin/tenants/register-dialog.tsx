import { revealLicenceKeys } from '@/components/admin/licences/reveal-keys';
import { type IssuedKeysReply } from '@/components/admin/licences/types';
import { Field } from '@/components/admin/tenants/field';
import { type TenantBranch, type TenantRegister } from '@/components/admin/tenants/types';
import { showToast } from '@/components/shared/toaster';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { sendJson } from '@/lib/http';
import { router, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

interface RegisterDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    tenantId: string;
    branch: TenantBranch;
    /** Edit this till; omit to add one. */
    register?: TenantRegister | null;
}

/** Add a till to a branch, or rename / re-code one. */
export function RegisterDialog(props: RegisterDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <RegisterDialogBody key={props.register?.id ?? 'new'} {...props} />}
        </Dialog>
    );
}

function nextCode(branch: TenantBranch): string {
    const used = new Set(branch.registers.map((register) => register.code));
    for (let n = 1; n <= 99; n++) {
        const code = String(n).padStart(2, '0');
        if (!used.has(code)) {
            return code;
        }
    }

    return '';
}

function RegisterDialogBody({ onOpenChange, tenantId, branch, register }: RegisterDialogProps) {
    const editing = Boolean(register);
    const suggested = nextCode(branch);
    const {
        data,
        setData,
        put,
        processing: saving,
        errors,
        setError,
        clearErrors,
    } = useForm<{ name: string; code: string; is_main_till: boolean }>({
        name: register?.name ?? '',
        code: register?.code ?? '',
        is_main_till: false,
    });
    const [adding, setAdding] = useState(false);
    const processing = saving || adding;

    // Adding a till issues its licence: the reply carries the new key, shown once in the "Licence key created" dialog.
    const add = async () => {
        setAdding(true);
        clearErrors();
        const result = await sendJson<IssuedKeysReply>('POST', route('admin.tenants.registers.store', [tenantId, branch.id]), data);
        setAdding(false);

        if (!result.ok || !result.data) {
            const { name, code, is_main_till, ...other } = result.errors;
            setError({ name, code, is_main_till });
            if (!name && !code && !is_main_till) {
                showToast(Object.values(other)[0] ?? result.message ?? 'The till could not be added.', 'error');
            }

            return;
        }

        onOpenChange(false);
        showToast(result.data.message, result.data.keys.length > 0 ? 'success' : 'error');
        revealLicenceKeys({ keys: result.data.keys, title: `${result.data.keys[0]?.tillName ?? 'Till'} added: licence key created` });
        router.reload({ only: ['branches', 'stats', 'licensing', 'activity'] });
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        if (register) {
            put(route('admin.tenants.registers.update', [tenantId, register.id]), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
        } else {
            void add();
        }
    };

    const hasActive = branch.registers.some((r) => r.isActive);

    return (
        <DialogContent onInteractOutside={(e) => processing && e.preventDefault()}>
            <form onSubmit={submit} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>{editing ? `Edit ${register?.name}` : `Add a till to ${branch.name}`}</DialogTitle>
                    <DialogDescription>
                        {editing
                            ? 'The till code appears in receipt numbers. Change it only before the till starts trading.'
                            : 'Each till has its own licence. It is issued straight away and you see its key once.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid grid-cols-1 gap-5 sm:grid-cols-[1fr_8rem]">
                    <Field
                        id="register-name"
                        label="Name"
                        optional={!editing}
                        hint={editing ? undefined : `Blank means “Till ${Number(suggested) || ''}”.`}
                        error={errors.name}
                    >
                        <Input
                            id="register-name"
                            autoFocus
                            maxLength={60}
                            value={data.name}
                            placeholder={editing ? undefined : `Till ${Number(suggested) || ''}`}
                            onChange={(e) => setData('name', e.target.value)}
                            aria-invalid={!!errors.name}
                        />
                    </Field>
                    <Field id="register-code" label="Code" optional={!editing} hint={editing ? undefined : `Next: ${suggested}`} error={errors.code}>
                        <Input
                            id="register-code"
                            inputMode="numeric"
                            maxLength={2}
                            className="font-mono"
                            value={data.code}
                            placeholder={editing ? undefined : suggested}
                            onChange={(e) => setData('code', e.target.value.replace(/\D/g, ''))}
                            aria-invalid={!!errors.code}
                        />
                    </Field>
                </div>

                {!editing && hasActive && (
                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="register-main"
                            checked={data.is_main_till}
                            onCheckedChange={(checked) => setData('is_main_till', checked === true)}
                            className="mt-0.5"
                        />
                        <div className="grid gap-1">
                            <Label htmlFor="register-main">Make this the main till</Label>
                            <p className="text-muted-foreground text-sm">The main till syncs with the portal for the whole branch.</p>
                        </div>
                    </div>
                )}

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        {editing ? 'Save changes' : 'Add till'}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
