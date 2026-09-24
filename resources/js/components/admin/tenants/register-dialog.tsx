import { Field } from '@/components/admin/tenants/field';
import { type TenantBranch, type TenantRegister } from '@/components/admin/tenants/types';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

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
    const { data, setData, post, put, processing, errors } = useForm<{ name: string; code: string; is_main_till: boolean }>({
        name: register?.name ?? '',
        code: register?.code ?? '',
        is_main_till: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onOpenChange(false) };

        if (register) {
            put(route('admin.tenants.registers.update', [tenantId, register.id]), options);
        } else {
            post(route('admin.tenants.registers.store', [tenantId, branch.id]), options);
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
                            : 'Each till needs its own licence. Licences arrive in module 1.3.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-5 sm:grid-cols-[1fr_8rem]">
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
