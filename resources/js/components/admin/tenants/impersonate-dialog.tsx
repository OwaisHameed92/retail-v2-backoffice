import { type TenantMember } from '@/components/admin/tenants/types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface ImpersonateDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    tenantId: string;
    tenantName: string;
    members: TenantMember[];
    /** Pre-selected user (from the users table). */
    userId?: number | null;
}

/** "Login as customer": choose which of the company's users to view the portal as. */
export function ImpersonateDialog(props: ImpersonateDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <ImpersonateDialogBody key={String(props.userId ?? 'default')} {...props} />}
        </Dialog>
    );
}

function ImpersonateDialogBody({ onOpenChange, tenantId, tenantName, members, userId }: ImpersonateDialogProps) {
    const active = members.filter((member) => member.isActive);
    const initial = userId ?? active.find((member) => member.isOwner)?.id ?? active[0]?.id ?? null;
    const { data, setData, post, processing, errors } = useForm<{ user_id: string }>({ user_id: initial ? String(initial) : '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.tenants.impersonate', tenantId));
    };

    return (
        <DialogContent onInteractOutside={(e) => processing && e.preventDefault()}>
            <form onSubmit={submit} className="grid gap-4">
                <DialogHeader>
                    <DialogTitle>Log in as a {tenantName} user?</DialogTitle>
                    <DialogDescription>
                        You will see the customer portal exactly as they do. A banner lets you return to admin at any time. This is recorded in the
                        activity log.
                    </DialogDescription>
                </DialogHeader>
                {active.length === 0 ? (
                    <p className="text-muted-foreground text-sm">This business has no active users to log in as.</p>
                ) : (
                    <div className="grid gap-2">
                        <Label htmlFor="impersonate-user">User</Label>
                        <Select value={data.user_id} onValueChange={(value) => setData('user_id', value)}>
                            <SelectTrigger id="impersonate-user">
                                <SelectValue placeholder="Choose a user" />
                            </SelectTrigger>
                            <SelectContent>
                                {active.map((member) => (
                                    <SelectItem key={member.id} value={String(member.id)}>
                                        {member.name} · {member.roleLabel}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.user_id} />
                    </div>
                )}
                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing || !data.user_id}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Log in as customer
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
