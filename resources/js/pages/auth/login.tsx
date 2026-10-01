import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, Mail } from 'lucide-react';
import { FormEventHandler } from 'react';

import { AuthDivider, AuthSubmit, IconInput, PasswordInput } from '@/components/auth/auth-fields';
import { FormField } from '@/components/shared/form-section';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

type LoginForm = {
    email: string;
    password: string;
    remember: boolean;
};

interface LoginProps {
    status?: string;
    canResetPassword: boolean;
}

export default function Login({ status, canResetPassword }: LoginProps) {
    const { data, setData, post, processing, errors, reset } = useForm<LoginForm>({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout title="Log in to your backoffice" description="Welcome back. Enter your email and password to continue.">
            <Head title="Log in" />

            {status && (
                <Alert variant="success" className="mb-6">
                    <AlertDescription>{status}</AlertDescription>
                </Alert>
            )}

            <form className="grid gap-5" onSubmit={submit}>
                <FormField id="email" label="Email address" error={errors.email}>
                    <IconInput
                        id="email"
                        icon={Mail}
                        type="email"
                        required
                        autoFocus
                        autoComplete="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        placeholder="you@yourshop.co.uk"
                        aria-invalid={!!errors.email || undefined}
                        aria-describedby={errors.email ? 'email-error' : undefined}
                    />
                </FormField>

                <FormField id="password" label="Password" error={errors.password}>
                    <PasswordInput
                        id="password"
                        required
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        placeholder="Your password"
                        aria-invalid={!!errors.password || undefined}
                        aria-describedby={errors.password ? 'password-error' : undefined}
                    />
                </FormField>

                <div className="flex items-center justify-between gap-3">
                    <div className="flex items-center gap-2.5">
                        <Checkbox
                            id="remember"
                            name="remember"
                            checked={data.remember}
                            onCheckedChange={(checked) => setData('remember', checked === true)}
                        />
                        <Label htmlFor="remember" className="font-normal">
                            Remember me
                        </Label>
                    </div>
                    {canResetPassword && (
                        <TextLink href={route('password.request')} className="text-[13px] font-medium">
                            Forgot password?
                        </TextLink>
                    )}
                </div>

                <AuthSubmit processing={processing} className="mt-1">
                    {processing ? 'Logging in…' : 'Log in'}
                </AuthSubmit>
            </form>

            <AuthDivider className="mt-8 mb-6" />

            <p className="text-muted-foreground text-center text-sm">
                New to Switch &amp; Save?{' '}
                <Link
                    href={route('trial')}
                    className="text-primary focus-visible:ring-ring/40 inline-flex items-center gap-1 rounded font-semibold outline-none hover:underline focus-visible:ring-[3px]"
                >
                    Start a free trial
                    <ArrowRight className="size-3.5" aria-hidden />
                </Link>
            </p>
        </AuthLayout>
    );
}
