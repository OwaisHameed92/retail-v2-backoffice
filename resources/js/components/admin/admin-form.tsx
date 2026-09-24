import { type AdminRoleValue, type RoleOption } from '@/components/admin/types';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Link } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

export interface AdminFormData {
    name: string;
    email: string;
    role: AdminRoleValue;
    password: string;
    password_confirmation: string;
    [key: string]: string;
}

interface AdminFormProps {
    data: AdminFormData;
    setData: <K extends keyof AdminFormData>(key: K, value: AdminFormData[K]) => void;
    errors: Partial<Record<keyof AdminFormData, string>>;
    processing: boolean;
    roles: RoleOption[];
    onSubmit: FormEventHandler;
    submitLabel: string;
    passwordOptional?: boolean;
}

export function AdminForm({ data, setData, errors, processing, roles, onSubmit, submitLabel, passwordOptional = false }: AdminFormProps) {
    return (
        <form onSubmit={onSubmit} className="grid max-w-xl gap-6">
            <div className="grid gap-2">
                <Label htmlFor="name">Name</Label>
                <Input id="name" required autoComplete="off" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                <InputError message={errors.name} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    required
                    autoComplete="off"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value.toLowerCase())}
                />
                <InputError message={errors.email} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="role">Role</Label>
                <Select value={data.role} onValueChange={(value) => setData('role', value as AdminRoleValue)}>
                    <SelectTrigger id="role">
                        <SelectValue placeholder="Choose a role" />
                    </SelectTrigger>
                    <SelectContent>
                        {roles.map((role) => (
                            <SelectItem key={role.value} value={role.value}>
                                {role.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <p className="text-muted-foreground text-xs">Only owners can manage admin users.</p>
                <InputError message={errors.role} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="password">{passwordOptional ? 'New password' : 'Password'}</Label>
                    <Input
                        id="password"
                        type="password"
                        required={!passwordOptional}
                        autoComplete="new-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                    />
                    <InputError message={errors.password} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="password_confirmation">Confirm password</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        required={!passwordOptional}
                        autoComplete="new-password"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                    />
                    <InputError message={errors.password_confirmation} />
                </div>
                <p className="text-muted-foreground text-xs sm:col-span-2">
                    {passwordOptional ? 'Leave blank to keep the current password. ' : ''}At least 12 characters.
                </p>
            </div>

            <div className="flex gap-2">
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                    {submitLabel}
                </Button>
                <Button variant="ghost" asChild>
                    <Link href={route('admin.admins.index')}>Cancel</Link>
                </Button>
            </div>
        </form>
    );
}
