import { Head, useForm } from '@inertiajs/react';
import { Mail } from 'lucide-react';
import { FormEventHandler } from 'react';

import { AuthSubmit, IconInput, PasswordInput } from '@/components/auth/auth-fields';
import { FormField } from '@/components/shared/form-section';
import AuthLayout from '@/layouts/auth-layout';

interface ResetPasswordProps {
    token: string;
    email: string;
    setup?: boolean;
}

type ResetPasswordForm = {
    token: string;
    email: string;
    password: string;
    password_confirmation: string;
};

export default function ResetPassword({ token, email, setup = false }: ResetPasswordProps) {
    const title = setup ? 'Set your password' : 'Reset password';
    const { data, setData, post, processing, errors, reset } = useForm<ResetPasswordForm>({
        token: token,
        email: email,
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('password.store'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout
            title={title}
            description={setup ? 'Choose a password to finish setting up your account.' : 'Choose a new password for your account.'}
        >
            <Head title={title} />

            <form onSubmit={submit} className="grid gap-5">
                <FormField id="email" label="Email" error={errors.email}>
                    <IconInput
                        id="email"
                        icon={Mail}
                        type="email"
                        name="email"
                        autoComplete="email"
                        value={data.email}
                        readOnly
                        className="bg-muted/60"
                        onChange={(e) => setData('email', e.target.value)}
                        aria-invalid={!!errors.email || undefined}
                        aria-describedby={errors.email ? 'email-error' : undefined}
                    />
                </FormField>

                <FormField id="password" label="New password" error={errors.password}>
                    <PasswordInput
                        id="password"
                        name="password"
                        autoComplete="new-password"
                        value={data.password}
                        autoFocus
                        onChange={(e) => setData('password', e.target.value)}
                        placeholder="New password"
                        aria-invalid={!!errors.password || undefined}
                        aria-describedby={errors.password ? 'password-error' : undefined}
                    />
                </FormField>

                <FormField id="password_confirmation" label="Confirm password" error={errors.password_confirmation}>
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        autoComplete="new-password"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        placeholder="Type it again"
                        aria-invalid={!!errors.password_confirmation || undefined}
                        aria-describedby={errors.password_confirmation ? 'password_confirmation-error' : undefined}
                    />
                </FormField>

                <AuthSubmit processing={processing} className="mt-1">
                    {setup ? 'Set password' : 'Reset password'}
                </AuthSubmit>
            </form>
        </AuthLayout>
    );
}
