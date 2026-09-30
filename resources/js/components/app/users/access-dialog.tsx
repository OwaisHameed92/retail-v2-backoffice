import { EVERY_SHOP, type PortalMember, type RoleOption, type ShopOption } from '@/components/app/users/types';
import { FormField } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type CompanyRole } from '@/types';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface AccessDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    roles: RoleOption[];
    branches: ShopOption[];
    validDays: number;
    /** Change this member's role and shop; omit to invite someone. */
    member?: PortalMember | null;
}

/** Invite someone (name, email, role, shop) or change a user's role and shop. */
export function AccessDialog(props: AccessDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <AccessDialogBody key={props.member?.id ?? 'new'} {...props} />}
        </Dialog>
    );
}

type AccessForm = { name: string; email: string; role: CompanyRole; branch_id: string };

function AccessDialogBody({ onOpenChange, roles, branches, validDays, member }: AccessDialogProps) {
    const editing = Boolean(member);
    const { data, setData, transform, post, put, processing, errors } = useForm<AccessForm>({
        name: '',
        email: '',
        role: member?.role ?? 'manager',
        branch_id: member?.branchId ?? EVERY_SHOP,
    });
    const role = roles.find((option) => option.value === data.role);
    const canLimit = role?.canLimitToShop ?? false;
    const unchanged = editing && data.role === member?.role && (data.branch_id === EVERY_SHOP ? null : data.branch_id) === member?.branchId;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onOpenChange(false) };

        transform((form) => {
            const branch = canLimit && form.branch_id !== EVERY_SHOP ? form.branch_id : null;

            return editing ? { role: form.role, branch_id: branch } : { ...form, branch_id: branch };
        });

        if (member) {
            put(route('app.users.update', member.id), options);
        } else {
            post(route('app.users.invitations.store'), options);
        }
    };

    return (
        <DialogContent onInteractOutside={(e) => processing && e.preventDefault()}>
            <form onSubmit={submit} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>{editing ? `Change ${member?.name}’s access` : 'Invite someone to your portal'}</DialogTitle>
                    <DialogDescription>
                        {editing
                            ? 'The new role and shop apply on their next page load.'
                            : `We email them a link to join. It works for ${validDays} days; you can resend it at any time.`}
                    </DialogDescription>
                </DialogHeader>

                {!editing && (
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField id="invite-name" label="Name" error={errors.name}>
                            <Input
                                id="invite-name"
                                autoFocus
                                autoComplete="off"
                                maxLength={255}
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                aria-invalid={!!errors.name}
                            />
                        </FormField>
                        <FormField id="invite-email" label="Email" error={errors.email}>
                            <Input
                                id="invite-email"
                                type="email"
                                autoComplete="off"
                                maxLength={255}
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value.trim())}
                                aria-invalid={!!errors.email}
                            />
                        </FormField>
                    </div>
                )}

                <FormField id="access-role" label="Role" help={role?.help} error={errors.role}>
                    <Select value={data.role} onValueChange={(value) => setData('role', value as CompanyRole)}>
                        <SelectTrigger id="access-role" aria-invalid={!!errors.role}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {roles.map((option) => (
                                <SelectItem key={option.value} value={option.value}>
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </FormField>

                <FormField
                    id="access-shop"
                    label="Shops"
                    help={
                        canLimit
                            ? 'Limit them to one shop to make them a shop manager: they only see that shop’s figures.'
                            : 'Owners always see every shop.'
                    }
                    error={errors.branch_id}
                >
                    <Select
                        value={canLimit ? data.branch_id : EVERY_SHOP}
                        onValueChange={(value) => setData('branch_id', value)}
                        disabled={!canLimit}
                    >
                        <SelectTrigger id="access-shop" aria-invalid={!!errors.branch_id}>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={EVERY_SHOP}>Every shop</SelectItem>
                            {branches.map((branch) => (
                                <SelectItem key={branch.id} value={branch.id}>
                                    {branch.name} only
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </FormField>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing || unchanged}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        {editing ? 'Save access' : 'Send invitation'}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
