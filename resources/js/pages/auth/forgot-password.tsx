import { Head, useForm } from '@inertiajs/react';
import { ArrowLeft, Mail } from 'lucide-react';
import { FormEventHandler } from 'react';

import { AuthDivider, AuthNotice, AuthSubmit, IconInput } from '@/components/auth/auth-fields';
import { FormField } from '@/components/shared/form-section';
import TextLink from '@/components/text-link';
import AuthLayout from '@/layouts/auth-layout';

export default function ForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('password.email'));
    };

    return (
        <AuthLayout title="Reset your password" description="Enter the email you log in with and we will send you a link to choose a new password.">
            <Head title="Forgot password" />

            {status && <AuthNotice>{status}</AuthNotice>}

            <form onSubmit={submit} className="grid gap-5">
                <FormField id="email" label="Email address" error={errors.email}>
                    <IconInput
                        id="email"
                        icon={Mail}
                        type="email"
                        name="email"
                        autoComplete="off"
                        value={data.email}
                        autoFocus
                        onChange={(e) => setData('email', e.target.value)}
                        placeholder="you@yourshop.co.uk"
                        aria-invalid={!!errors.email || undefined}
                        aria-describedby={errors.email ? 'email-error' : undefined}
                    />
                </FormField>

                <AuthSubmit processing={processing} className="mt-1">
                    Email password reset link
                </AuthSubmit>
            </form>

            <AuthDivider className="mt-8 mb-6" />

            <p className="text-muted-foreground text-center text-sm">
                Remembered it?{' '}
                <TextLink href={route('login')} className="inline-flex items-center gap-1 font-semibold">
                    <ArrowLeft className="size-3.5" aria-hidden />
                    Back to log in
                </TextLink>
            </p>
        </AuthLayout>
    );
}
