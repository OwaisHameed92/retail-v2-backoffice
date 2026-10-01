import HeadingSmall from '@/components/heading-small';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { FormField } from '@/components/shared/form-section';
import { RegenerateCodesDialog } from '@/components/shared/two-factor/regenerate-codes-dialog';
import { TwoFactorStatus, type TwoFactorState } from '@/components/shared/two-factor/two-factor-status';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { CircleAlert, LoaderCircle, ShieldCheck } from 'lucide-react';
import { useState, type FormEvent } from 'react';

interface SecurityProps {
    twoFactor: TwoFactorState;
    company: { name: string; requireTwoFactor: boolean; canManage: boolean };
}

/** Settings → Security: the user's own two-factor sign-in and, for the owner, requiring it in the business. */
export default function Security({ twoFactor, company }: SecurityProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [requireOpen, setRequireOpen] = useState(false);

    const setRequired = (value: boolean) =>
        new Promise<void>((resolve) =>
            router.put(route('security.company'), { requireTwoFactor: value }, { preserveScroll: true, onFinish: () => resolve() }),
        );

    return (
        <AppLayout>
            <Head title="Security" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Two-factor sign-in"
                        description="After your password, enter a 6-digit code from an authenticator app on your phone."
                    />

                    <TwoFactorStatus
                        state={twoFactor}
                        actions={
                            twoFactor.enabled ? (
                                <>
                                    <RegenerateCodesDialog url={route('security.recovery-codes')} only={['twoFactor']} />
                                    {!company.requireTwoFactor && <DisableTwoFactorDialog />}
                                </>
                            ) : (
                                <Button asChild>
                                    <Link href={route('two-factor.setup', { from: 'settings' })}>
                                        <ShieldCheck />
                                        Set up
                                    </Link>
                                </Button>
                            )
                        }
                    />

                    {twoFactor.enabled && company.requireTwoFactor && (
                        <p className="text-muted-foreground text-sm">{company.name} requires two-factor sign-in, so it cannot be turned off.</p>
                    )}
                </div>

                {company.canManage && (
                    <div className="space-y-6 border-t pt-8">
                        <HeadingSmall
                            title={`Require two-factor sign-in at ${company.name}`}
                            description="Everyone who uses this backoffice must set up an authenticator app before they can go on."
                        />

                        {errors.requireTwoFactor && (
                            <Alert variant="destructive">
                                <CircleAlert className="size-4" />
                                <AlertDescription>{errors.requireTwoFactor}</AlertDescription>
                            </Alert>
                        )}

                        <div className="flex items-start justify-between gap-4 rounded-lg border p-4">
                            <div className="grid gap-1">
                                <span className="text-sm font-medium">Require for everyone</span>
                                <p className="text-muted-foreground text-sm">
                                    {company.requireTwoFactor
                                        ? 'On. People without it are asked to set it up at their next visit.'
                                        : twoFactor.enabled
                                          ? 'Off. Each person chooses for themselves.'
                                          : 'Turn on two-factor sign-in for your own account first.'}
                                </p>
                            </div>
                            {company.requireTwoFactor ? (
                                <ConfirmDialog
                                    trigger={
                                        <Button variant="outline" className="shrink-0">
                                            Make optional
                                        </Button>
                                    }
                                    title="Make two-factor sign-in optional?"
                                    description="People who already use it keep it; everyone else can sign in with a password only."
                                    confirmLabel="Make optional"
                                    onConfirm={() => setRequired(false)}
                                />
                            ) : (
                                <Button className="shrink-0" disabled={!twoFactor.enabled} onClick={() => setRequireOpen(true)}>
                                    Require
                                </Button>
                            )}
                        </div>

                        <ConfirmDialog
                            open={requireOpen}
                            onOpenChange={setRequireOpen}
                            title={`Require two-factor sign-in at ${company.name}?`}
                            description="Everyone without it is stopped at their next visit until they set up an authenticator app. You can turn this off later."
                            confirmLabel="Require two-factor"
                            onConfirm={() => setRequired(true)}
                        />
                    </div>
                )}
            </SettingsLayout>
        </AppLayout>
    );
}

function DisableTwoFactorDialog() {
    const [open, setOpen] = useState(false);
    const form = useForm({ password: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.delete(route('security.two-factor.destroy'), {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
            onFinish: () => form.reset('password'),
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                setOpen(value);
                form.clearErrors();
            }}
        >
            <DialogTrigger asChild>
                <Button variant="outline">Turn off</Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Turn off two-factor sign-in?</DialogTitle>
                    <DialogDescription>Your password alone will open your account. Your recovery codes stop working.</DialogDescription>
                </DialogHeader>
                <form className="grid gap-4" onSubmit={submit}>
                    <FormField id="disable-password" label="Password" error={form.errors.password}>
                        <Input
                            id="disable-password"
                            type="password"
                            autoComplete="current-password"
                            required
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                            aria-invalid={!!form.errors.password || undefined}
                        />
                    </FormField>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" variant="destructive" disabled={form.processing || form.data.password === ''}>
                            {form.processing && <LoaderCircle className="size-4 animate-spin" />}
                            Turn off
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
