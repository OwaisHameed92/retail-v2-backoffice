import { AuthSubmit } from '@/components/auth/auth-fields';
import { FormField } from '@/components/shared/form-section';
import { CodeInput } from '@/components/shared/two-factor/code-input';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';

interface TwoFactorChallengeProps {
    staff: boolean;
    email: string;
    verifyUrl: string;
    logoutUrl: string;
    rememberDays: number;
}

/** The second sign-in step: a 6-digit code from the authenticator app, or a recovery code. */
export default function TwoFactorChallenge({ staff, email, verifyUrl, logoutUrl, rememberDays }: TwoFactorChallengeProps) {
    const [recovery, setRecovery] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm<{ code: string; remember: boolean }>({
        code: '',
        remember: false,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        post(verifyUrl, { onError: () => reset('code') });
    };

    const toggle = () => {
        setRecovery((value) => !value);
        reset('code');
        clearErrors();
    };

    return (
        <AuthLayout
            variant={staff ? 'staff' : undefined}
            title="Enter your sign-in code"
            description={
                recovery
                    ? 'Enter one of the recovery codes you saved. Each code works once.'
                    : 'Open your authenticator app and enter the 6-digit code.'
            }
        >
            <Head title="Sign-in code" />

            <form className="grid gap-5" onSubmit={submit}>
                <FormField id="code" label={recovery ? 'Recovery code' : 'Code'} error={errors.code}>
                    <CodeInput
                        id="code"
                        key={recovery ? 'recovery' : 'totp'}
                        allowRecovery={recovery}
                        autoFocus
                        required
                        placeholder={recovery ? 'abcde-23456' : undefined}
                        value={data.code}
                        onChange={(e) => setData('code', recovery ? e.target.value : e.target.value.replace(/\D/g, ''))}
                        aria-invalid={!!errors.code || undefined}
                    />
                </FormField>

                <div className="flex items-center gap-2.5">
                    <Checkbox id="remember" checked={data.remember} onCheckedChange={(checked) => setData('remember', checked === true)} />
                    <Label htmlFor="remember" className="font-normal">
                        Remember this device for {rememberDays} days
                    </Label>
                </div>

                <AuthSubmit processing={processing} disabled={data.code.trim() === ''}>
                    Verify and sign in
                </AuthSubmit>

                <button
                    type="button"
                    onClick={toggle}
                    className="text-primary focus-visible:ring-ring/40 rounded text-sm font-medium outline-none hover:underline focus-visible:ring-[3px]"
                >
                    {recovery ? 'Use a code from my app instead' : 'Lost your phone? Use a recovery code'}
                </button>
            </form>

            <div className="text-muted-foreground mt-8 flex items-center justify-between border-t pt-6 text-[13px]">
                <span className="truncate">Signed in as {email}</span>
                <Link href={logoutUrl} method="post" as="button" className="text-primary shrink-0 font-medium hover:underline">
                    Log out
                </Link>
            </div>
            {staff && <p className="text-muted-foreground mt-4 text-center text-xs">No codes left? Ask an owner to reset your two-factor sign-in.</p>}
            {!staff && (
                <p className="text-muted-foreground mt-4 text-center text-xs">
                    No codes left? Contact Switch &amp; Save support: after checking it is you, they can reset it.
                </p>
            )}
        </AuthLayout>
    );
}
