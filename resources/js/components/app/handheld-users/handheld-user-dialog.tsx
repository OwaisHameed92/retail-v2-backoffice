import { type HandheldShop, type HandheldUser } from '@/components/app/handheld-users/types';
import { FormField } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface HandheldUserDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    shops: HandheldShop[];
    /** Edit this handheld user; omit to add one. */
    user?: HandheldUser | null;
}

/** Add a handheld user (name, email, shop, password, price permission) or edit one (no password here). */
export function HandheldUserDialog(props: HandheldUserDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <HandheldUserDialogBody key={props.user?.id ?? 'new'} {...props} />}
        </Dialog>
    );
}

type HandheldForm = {
    name: string;
    email: string;
    branch_id: string;
    can_change_prices: boolean;
    password: string;
    password_confirmation: string;
};

function HandheldUserDialogBody({ onOpenChange, shops, user }: HandheldUserDialogProps) {
    const editing = Boolean(user);
    const shopListed = user ? shops.some((shop) => shop.id === user.shopId) : true;
    const { data, setData, post, put, transform, processing, errors } = useForm<HandheldForm>({
        name: user?.name ?? '',
        email: user?.email ?? '',
        branch_id: user?.shopId ?? (shops.length === 1 ? shops[0].id : ''),
        can_change_prices: user?.canChangePrices ?? false,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onOpenChange(false) };

        if (user) {
            transform((form) => ({ name: form.name, email: form.email, branch_id: form.branch_id, can_change_prices: form.can_change_prices }));
            put(route('app.handheld-users.update', user.id), options);
        } else {
            post(route('app.handheld-users.store'), options);
        }
    };

    return (
        <DialogContent onInteractOutside={(e) => processing && e.preventDefault()}>
            <form onSubmit={submit} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>{editing ? `Edit ${user?.name}` : 'Add a handheld user'}</DialogTitle>
                    <DialogDescription>
                        {editing
                            ? 'Changes apply at the device’s next request. To change the password, use “Set a new password”.'
                            : 'They sign in to the PDA app with this email and password. Give them the password yourself; we do not email it.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <FormField id="handheld-name" label="Name" help="A person or a device, like “Stock room 1”." error={errors.name}>
                        <Input
                            id="handheld-name"
                            autoFocus
                            autoComplete="off"
                            maxLength={120}
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            aria-invalid={!!errors.name}
                        />
                    </FormField>
                    <FormField id="handheld-email" label="Sign-in email" error={errors.email}>
                        <Input
                            id="handheld-email"
                            type="email"
                            autoComplete="off"
                            maxLength={255}
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value.trim())}
                            aria-invalid={!!errors.email}
                        />
                    </FormField>
                </div>

                <FormField id="handheld-shop" label="Shop" help="Everything they look up and order is for this shop." error={errors.branch_id}>
                    <Select value={data.branch_id} onValueChange={(value) => setData('branch_id', value)}>
                        <SelectTrigger id="handheld-shop" aria-invalid={!!errors.branch_id}>
                            <SelectValue placeholder="Choose a shop" />
                        </SelectTrigger>
                        <SelectContent>
                            {!shopListed && user && (
                                <SelectItem value={user.shopId} disabled>
                                    {user.shopName ?? 'Removed shop'} (closed)
                                </SelectItem>
                            )}
                            {shops.map((shop) => (
                                <SelectItem key={shop.id} value={shop.id}>
                                    {shop.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </FormField>

                {!editing && (
                    <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <FormField id="handheld-password" label="Password" help="At least 10 characters." error={errors.password}>
                            <Input
                                id="handheld-password"
                                type="password"
                                autoComplete="new-password"
                                maxLength={255}
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                aria-invalid={!!errors.password}
                            />
                        </FormField>
                        <FormField id="handheld-password-confirmation" label="Password again" error={errors.password_confirmation}>
                            <Input
                                id="handheld-password-confirmation"
                                type="password"
                                autoComplete="new-password"
                                maxLength={255}
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                aria-invalid={!!errors.password_confirmation}
                            />
                        </FormField>
                    </div>
                )}

                <div className="flex items-start gap-3 rounded-lg border p-3">
                    <Checkbox
                        id="handheld-prices"
                        checked={data.can_change_prices}
                        onCheckedChange={(on) => setData('can_change_prices', on === true)}
                        className="mt-0.5"
                    />
                    <div className="grid gap-1">
                        <Label htmlFor="handheld-prices" className="font-medium">
                            Can change selling prices
                        </Label>
                        <p className="text-muted-foreground text-sm">
                            Changes the business price of a product from the handheld. Every till gets the new price at its next sync.
                        </p>
                    </div>
                </div>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing || data.branch_id === ''}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        {editing ? 'Save changes' : 'Add handheld user'}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
