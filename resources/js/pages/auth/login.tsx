import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import { FormField } from '@/components/shared/form-section';
import TextLink from '@/components/text-link';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
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
                    <Input
                        id="email"
                        type="email"
                        required
                        autoFocus
                        tabIndex={1}
                        autoComplete="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        placeholder="you@yourshop.co.uk"
                        aria-invalid={!!errors.email || undefined}
                    />
                </FormField>

                <FormField
                    id="password"
                    label="Password"
                    error={errors.password}
                    labelAside={
                        canResetPassword && (
                            <TextLink href={route('password.request')} className="text-[13px]" tabIndex={5}>
                                Forgot password?
                            </TextLink>
                        )
                    }
                >
                    <Input
                        id="password"
                        type="password"
                        required
                        tabIndex={2}
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        aria-invalid={!!errors.password || undefined}
                    />
                </FormField>

                <div className="flex items-center gap-2.5">
                    <Checkbox
                        id="remember"
                        name="remember"
                        tabIndex={3}
                        checked={data.remember}
                        onCheckedChange={(checked) => setData('remember', checked === true)}
                    />
                    <Label htmlFor="remember" className="font-normal">
                        Remember me
                    </Label>
                </div>

                <Button type="submit" size="lg" className="mt-1 w-full" tabIndex={4} disabled={processing}>
                    {processing && <LoaderCircle className="size-4 animate-spin" />}
                    Log in
                </Button>
            </form>
        </AuthLayout>
    );
}
