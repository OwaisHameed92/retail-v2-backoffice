import { type AdminRoleValue, type RoleOption } from '@/components/admin/types';
import { FormCard, FormField, FormGrid, FormSection } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
    const invalid = (key: keyof AdminFormData) => (errors[key] ? { 'aria-invalid': true, 'aria-describedby': `${String(key)}-error` } : {});

    return (
        <form onSubmit={onSubmit} className="grid max-w-4xl gap-6">
            <FormCard>
                <FormSection title="Profile" description="Their name as colleagues know it and the email they log in with.">
                    <FormGrid>
                        <FormField id="name" label="Name" error={errors.name}>
                            <Input
                                id="name"
                                required
                                autoComplete="off"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                {...invalid('name')}
                            />
                        </FormField>
                        <FormField id="email" label="Email address" error={errors.email}>
                            <Input
                                id="email"
                                type="email"
                                required
                                autoComplete="off"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value.toLowerCase())}
                                {...invalid('email')}
                            />
                        </FormField>
                    </FormGrid>
                </FormSection>

                <FormSection title="Access" description="What they can see and change in the admin area.">
                    <FormField id="role" label="Role" error={errors.role} help="Only owners can manage admin users." className="sm:max-w-xs">
                        <Select value={data.role} onValueChange={(value) => setData('role', value as AdminRoleValue)}>
                            <SelectTrigger id="role" {...invalid('role')}>
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
                    </FormField>
                </FormSection>

                <FormSection
                    title={passwordOptional ? 'Change password' : 'Password'}
                    description={
                        passwordOptional ? 'Leave blank to keep the current password.' : 'At least 12 characters. Share it with them in person.'
                    }
                >
                    <FormGrid>
                        <FormField
                            id="password"
                            label={passwordOptional ? 'New password' : 'Password'}
                            error={errors.password}
                            help="At least 12 characters."
                        >
                            <Input
                                id="password"
                                type="password"
                                required={!passwordOptional}
                                autoComplete="new-password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                {...invalid('password')}
                            />
                        </FormField>
                        <FormField id="password_confirmation" label="Confirm password" error={errors.password_confirmation}>
                            <Input
                                id="password_confirmation"
                                type="password"
                                required={!passwordOptional}
                                autoComplete="new-password"
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                {...invalid('password_confirmation')}
                            />
                        </FormField>
                    </FormGrid>
                </FormSection>

                <div className="bg-subtle flex flex-col-reverse gap-2 px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
                    <Button variant="outline" asChild>
                        <Link href={route('admin.admins.index')}>Cancel</Link>
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        {submitLabel}
                    </Button>
                </div>
            </FormCard>
        </form>
    );
}
