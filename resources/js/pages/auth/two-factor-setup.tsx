import { AuthSubmit } from '@/components/auth/auth-fields';
import { FormField } from '@/components/shared/form-section';
import { CodeInput } from '@/components/shared/two-factor/code-input';
import { RecoveryCodesPanel } from '@/components/shared/two-factor/recovery-codes-panel';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { sendJson } from '@/lib/http';
import { Head, Link, router } from '@inertiajs/react';
import { Check, Copy, ShieldCheck } from 'lucide-react';
import { useState, type FormEvent } from 'react';

interface TwoFactorSetupProps {
    staff: boolean;
    email: string;
    setup: { qrSvg: string; secret: string; otpauthUrl: string };
    confirmUrl: string;
    continueUrl: string;
    logoutUrl: string;
    /** Every admin; a portal user whose business requires it. */
    required: boolean;
    requiredBy: string | null;
    /** Back to Settings → Security when it is optional. */
    cancelUrl?: string | null;
}

/** Set up an authenticator app: scan (or type) the key, confirm with a code, then save the recovery codes. */
export default function TwoFactorSetup({
    staff,
    email,
    setup,
    confirmUrl,
    continueUrl,
    logoutUrl,
    required,
    requiredBy,
    cancelUrl,
}: TwoFactorSetupProps) {
    const [code, setCode] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [copied, setCopied] = useState(false);
    const [codes, setCodes] = useState<string[] | null>(null);

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(null);
        const result = await sendJson<{ recoveryCodes: string[] }>('POST', confirmUrl, { code });
        setBusy(false);

        if (result.ok && result.data) {
            setCodes(result.data.recoveryCodes);
        } else {
            setError(result.errors.code ?? result.message);
            setCode('');
        }
    };

    const copyKey = async () => {
        try {
            await navigator.clipboard.writeText(setup.secret.replace(/\s/g, ''));
            setCopied(true);
            window.setTimeout(() => setCopied(false), 2000);
        } catch {
            setCopied(false);
        }
    };

    const intro = required
        ? staff
            ? 'Every Switch & Save admin signs in with a password and a code from an authenticator app.'
            : `${requiredBy ?? 'Your business'} asks everyone to sign in with a password and a code from an authenticator app.`
        : 'Add a code from an authenticator app to your password, so a stolen password alone cannot open your account.';

    if (codes) {
        return (
            <AuthLayout variant={staff ? 'staff' : undefined} title="Save your recovery codes" description="Two-factor sign-in is on.">
                <Head title="Recovery codes" />
                <RecoveryCodesPanel codes={codes} doneLabel="Continue" onDone={() => router.visit(continueUrl)} />
            </AuthLayout>
        );
    }

    return (
        <AuthLayout variant={staff ? 'staff' : undefined} title="Set up two-factor sign-in" description={intro}>
            <Head title="Set up two-factor sign-in" />

            <ol className="grid gap-6">
                <li className="grid gap-3">
                    <p className="text-sm font-medium">1. Scan this QR code with an authenticator app</p>
                    <p className="text-muted-foreground text-sm">
                        Google Authenticator, Microsoft Authenticator, 1Password or any app that shows 6-digit codes.
                    </p>
                    <div className="flex flex-col items-center gap-4 rounded-lg border p-4 sm:flex-row sm:items-start">
                        <div
                            role="img"
                            aria-label={`QR code for ${email}`}
                            className="size-[168px] shrink-0 rounded-md bg-white p-2 [&_svg]:size-full"
                            dangerouslySetInnerHTML={{ __html: setup.qrSvg }}
                        />
                        <div className="grid min-w-0 gap-2 text-sm">
                            <p className="text-muted-foreground">Cannot scan it? Enter this key in the app instead:</p>
                            <code className="bg-muted rounded-md px-2.5 py-2 font-mono text-[13px] break-all select-all">{setup.secret}</code>
                            <Button type="button" variant="outline" size="sm" className="w-fit" onClick={copyKey}>
                                {copied ? <Check className="text-success" /> : <Copy />}
                                {copied ? 'Copied' : 'Copy key'}
                            </Button>
                            <p className="text-muted-foreground text-xs">Account: {email}</p>
                        </div>
                    </div>
                </li>

                <li className="grid gap-3">
                    <p className="text-sm font-medium">2. Enter the 6-digit code the app shows</p>
                    <form className="grid gap-4" onSubmit={submit}>
                        <FormField id="code" label="Code" error={error ?? undefined}>
                            <CodeInput
                                id="code"
                                autoFocus
                                required
                                value={code}
                                onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))}
                                aria-invalid={!!error || undefined}
                            />
                        </FormField>
                        <AuthSubmit processing={busy} disabled={code.length !== 6} icon={ShieldCheck}>
                            Turn on two-factor sign-in
                        </AuthSubmit>
                    </form>
                </li>
            </ol>

            {required && (
                <Alert variant="info" className="mt-6">
                    <AlertDescription>You need to finish this before you can use the {staff ? 'admin console' : 'backoffice'}.</AlertDescription>
                </Alert>
            )}

            <div className="text-muted-foreground mt-8 flex items-center justify-between border-t pt-6 text-[13px]">
                {cancelUrl ? (
                    <Link href={cancelUrl} className="text-primary font-medium hover:underline">
                        Not now
                    </Link>
                ) : (
                    <span>Signed in as {email}</span>
                )}
                <Link href={logoutUrl} method="post" as="button" className="text-primary font-medium hover:underline">
                    Log out
                </Link>
            </div>
        </AuthLayout>
    );
}
