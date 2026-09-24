import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import { FormField } from '@/components/shared/form-section';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
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
        <AuthLayout variant="staff" title="Sign in to the admin console" description="Use your Switch & Save staff account.">
            <Head title="Admin log in" />

            {status && (
                <Alert variant="success" className="mb-6">
                    <AlertDescription>{status}</AlertDescription>
                </Alert>
            )}

            <form className="grid gap-5" onSubmit={submit}>
                <FormField id="email" label="Work email" error={errors.email}>
                    <Input
                        id="email"
                        type="email"
                        required
                        autoFocus
                        autoComplete="username"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        aria-invalid={!!errors.email || undefined}
                    />
                </FormField>

                <FormField id="password" label="Password" error={errors.password}>
                    <Input
                        id="password"
                        type="password"
                        required
                        autoComplete="current-password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        aria-invalid={!!errors.password || undefined}
                    />
                </FormField>

                <div className="flex items-center gap-2.5">
                    <Checkbox id="remember" checked={data.remember} onCheckedChange={(checked) => setData('remember', checked === true)} />
                    <Label htmlFor="remember" className="font-normal">
                        Keep me signed in on this computer
                    </Label>
                </div>

                <Button type="submit" size="lg" className="mt-1 w-full" disabled={processing}>
                    {processing && <LoaderCircle className="size-4 animate-spin" />}
                    Sign in
                </Button>
            </form>

            <p className="text-muted-foreground mt-8 border-t pt-6 text-center text-[13px]">
                Not Switch &amp; Save staff?{' '}
                <a href={route('login')} className="text-primary font-medium hover:underline">
                    Log in to your backoffice
                </a>
            </p>
        </AuthLayout>
    );
}
