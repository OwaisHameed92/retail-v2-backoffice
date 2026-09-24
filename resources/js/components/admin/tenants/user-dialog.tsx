import { Field } from '@/components/admin/tenants/field';
import { type Option, type TenantMember } from '@/components/admin/tenants/types';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type CompanyRole } from '@/types';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface UserDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    tenantId: string;
    tenantName: string;
    roles: Option<CompanyRole>[];
    /** Change this member's role; omit to add a user. */
    member?: TenantMember | null;
}

const roleHelp: Record<CompanyRole, string> = {
    owner: 'Everything, including users and billing.',
    manager: 'Runs the shops: products, prices, stock, customers, reports.',
    accountant: 'Sales, reports and billing, read only.',
    staff: 'Read-only views of sales, products, stock and customers.',
};

export function UserDialog(props: UserDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <UserDialogBody key={props.member?.id ?? 'new'} {...props} />}
        </Dialog>
    );
}

function UserDialogBody({ onOpenChange, tenantId, tenantName, roles, member }: UserDialogProps) {
    const editing = Boolean(member);
    const { data, setData, post, put, processing, errors } = useForm<{ name: string; email: string; role: CompanyRole }>({
        name: member?.name ?? '',
        email: member?.email ?? '',
        role: member?.role ?? 'manager',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onOpenChange(false) };

        if (member) {
            put(route('admin.tenants.users.update', [tenantId, member.id]), options);
        } else {
            post(route('admin.tenants.users.store', tenantId), options);
        }
    };

    return (
        <DialogContent onInteractOutside={(e) => processing && e.preventDefault()}>
            <form onSubmit={submit} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>{editing ? `Change ${member?.name}’s role` : `Add a user to ${tenantName}`}</DialogTitle>
                    <DialogDescription>
                        {editing ? 'The new role applies on their next page load.' : 'New users get an email with a link to set their password.'}
                    </DialogDescription>
                </DialogHeader>

                {!editing && (
                    <div className="grid gap-5 sm:grid-cols-2">
                        <Field id="user-name" label="Name" error={errors.name}>
                            <Input
                                id="user-name"
                                autoFocus
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                aria-invalid={!!errors.name}
                            />
                        </Field>
                        <Field id="user-email" label="Email" error={errors.email}>
                            <Input
                                id="user-email"
                                type="email"
                                autoComplete="off"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value.trim())}
                                aria-invalid={!!errors.email}
                            />
                        </Field>
                    </div>
                )}

                <Field
                    id="user-role"
                    label="Role"
                    hint={roleHelp[data.role]}
                    error={errors.role ?? (errors as Record<string, string | undefined>).user}
                >
                    <Select value={data.role} onValueChange={(value) => setData('role', value as CompanyRole)}>
                        <SelectTrigger id="user-role">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {roles.map((role) => (
                                <SelectItem key={role.value} value={role.value}>
                                    {role.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing || (editing && data.role === member?.role)}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        {editing ? 'Change role' : 'Add user'}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
