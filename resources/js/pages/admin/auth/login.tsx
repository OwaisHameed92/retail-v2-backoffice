import { Head, useForm } from '@inertiajs/react';
import { ArrowRight, Mail } from 'lucide-react';
import { FormEventHandler } from 'react';

import { AuthDivider, AuthSubmit, IconInput, PasswordInput } from '@/components/auth/auth-fields';
import { FormField } from '@/components/shared/form-section';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

type AdminLoginForm = {
    email: string;
    password: string;
    remember: boolean;
};

export default function AdminLogin({ status }: { status?: string }) {
    const { data, setData, post, processing, errors, reset } = useForm<AdminLoginForm>({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('admin.login.store'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout variant="staff" title="Sign in to the admin console" description="Welcome back. Use your Switch & Save staff account.">
            <Head title="Admin log in" />

            {status && (
                <Alert variant="success" className="mb-6">
                    <AlertDescription>{status}</AlertDescription>
                </Alert>
            )}

            <form className="grid gap-5" onSubmit={submit}>
                <FormField id="email" label="Work email" error={errors.email}>
                    <IconInput
                        id="email"
                        icon={Mail}
                        type="email"
                        required
                        autoFocus
                        autoComplete="username"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
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

                <div className="flex items-center gap-2.5">
                    <Checkbox id="remember" checked={data.remember} onCheckedChange={(checked) => setData('remember', checked === true)} />
                    <Label htmlFor="remember" className="font-normal">
                        Keep me signed in on this computer
                    </Label>
                </div>

                <AuthSubmit processing={processing} className="mt-1">
                    {processing ? 'Signing in…' : 'Sign in'}
                </AuthSubmit>

                <p className="text-muted-foreground text-center text-xs">Forgot your password? Ask a Switch &amp; Save owner to reset it.</p>
            </form>

            <AuthDivider className="mt-8 mb-6" />

            <p className="text-muted-foreground text-center text-sm">
                Not Switch &amp; Save staff?{' '}
                <a
                    href={route('login')}
                    className="text-primary focus-visible:ring-ring/40 inline-flex items-center gap-1 rounded font-semibold outline-none hover:underline focus-visible:ring-[3px]"
                >
                    Log in to your backoffice
                    <ArrowRight className="size-3.5" aria-hidden />
                </a>
            </p>
        </AuthLayout>
    );
}
