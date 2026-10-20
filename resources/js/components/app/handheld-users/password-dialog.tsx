import { type HandheldUser } from '@/components/app/handheld-users/types';
import { FormField } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface PasswordDialogProps {
    user: HandheldUser | null;
    onOpenChange: (open: boolean) => void;
}

/** Set a new password for a handheld user; every device they are signed in on is signed out. */
export function PasswordDialog({ user, onOpenChange }: PasswordDialogProps) {
    return (
        <Dialog open={user !== null} onOpenChange={onOpenChange}>
            {user && <PasswordDialogBody key={user.id} user={user} onOpenChange={onOpenChange} />}
        </Dialog>
    );
}

function PasswordDialogBody({ user, onOpenChange }: { user: HandheldUser; onOpenChange: (open: boolean) => void }) {
    const { data, setData, post, processing, errors } = useForm({ password: '', password_confirmation: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('app.handheld-users.password', user.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <DialogContent onInteractOutside={(e) => processing && e.preventDefault()}>
            <form onSubmit={submit} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>Set a new password for {user.name}</DialogTitle>
                    <DialogDescription>
                        {user.devices > 0
                            ? `${user.devices === 1 ? 'The device' : `All ${user.devices} devices`} signed in as ${user.email} will be signed out and need the new password.`
                            : `${user.email} signs in with the new password from now on.`}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <FormField id="reset-password" label="New password" help="At least 10 characters." error={errors.password}>
                        <Input
                            id="reset-password"
                            type="password"
                            autoFocus
                            autoComplete="new-password"
                            maxLength={255}
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            aria-invalid={!!errors.password}
                        />
                    </FormField>
                    <FormField id="reset-password-confirmation" label="New password again" error={errors.password_confirmation}>
                        <Input
                            id="reset-password-confirmation"
                            type="password"
                            autoComplete="new-password"
                            maxLength={255}
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            aria-invalid={!!errors.password_confirmation}
                        />
                    </FormField>
                </div>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Set password
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
