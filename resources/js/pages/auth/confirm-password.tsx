import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import { AuthSubmit, PasswordInput } from '@/components/auth/auth-fields';
import { FormField } from '@/components/shared/form-section';
import AuthLayout from '@/layouts/auth-layout';

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm({
        password: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('password.confirm'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout title="Confirm your password" description="This is a secure area. Confirm your password before you continue.">
            <Head title="Confirm password" />

            <form onSubmit={submit} className="grid gap-5">
                <FormField id="password" label="Password" error={errors.password}>
                    <PasswordInput
                        id="password"
                        name="password"
                        placeholder="Your password"
                        autoComplete="current-password"
                        value={data.password}
                        autoFocus
                        onChange={(e) => setData('password', e.target.value)}
                        aria-invalid={!!errors.password || undefined}
                        aria-describedby={errors.password ? 'password-error' : undefined}
                    />
                </FormField>

                <AuthSubmit processing={processing} className="mt-1">
                    Confirm password
                </AuthSubmit>
            </form>
        </AuthLayout>
    );
}
