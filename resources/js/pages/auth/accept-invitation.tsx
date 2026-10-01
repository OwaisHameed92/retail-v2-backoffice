import { AuthSubmit, IconInput, PasswordInput } from '@/components/auth/auth-fields';
import { FormField } from '@/components/shared/form-section';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { LoaderCircle, Mail, UserRound } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';

type LinkState = 'invalid' | 'expired' | 'revoked' | 'accepted' | 'register' | 'signIn' | 'join' | 'wrongAccount';

/** Matches `PortalUsers\Data\InvitationLinkState::for()` (module 4.1). */
interface AcceptInvitationProps {
    state: LinkState;
    businessName?: string;
    inviterName?: string | null;
    email?: string;
    name?: string;
    roleLabel?: string;
    branchName?: string | null;
    expiresAt?: string;
    signedInAs?: string | null;
    url?: string;
}

const closed: Partial<Record<LinkState, { title: string; body: (p: AcceptInvitationProps) => string }>> = {
    invalid: { title: 'Invitation not found', body: () => 'This invitation link is not valid. Ask the business owner to send a new one.' },
    expired: {
        title: 'Invitation expired',
        body: (p) => `This invitation to ${p.businessName} has expired. Ask ${p.inviterName ?? 'the owner'} to send a new one.`,
    },
    revoked: {
        title: 'Invitation cancelled',
        body: (p) => `This invitation to ${p.businessName} was cancelled. Ask the owner if you still need access.`,
    },
    accepted: {
        title: 'Invitation already used',
        body: (p) => `This invitation to ${p.businessName} has been accepted. Sign in to open the portal.`,
    },
};

export default function AcceptInvitation(props: AcceptInvitationProps) {
    const { state } = props;
    const closedCopy = closed[state];
    const errors = usePage().props.errors as Record<string, string | undefined>;

    if (closedCopy) {
        return (
            <AuthLayout title={closedCopy.title} description={closedCopy.body(props)}>
                <Head title={closedCopy.title} />
                <Button asChild size="lg" className="h-11 w-full text-[15px] font-semibold">
                    <Link href={route('login')}>Go to sign in</Link>
                </Button>
            </AuthLayout>
        );
    }

    const access = `${props.roleLabel}${props.branchName ? ` for the ${props.branchName} shop` : ''}`;

    return (
        <AuthLayout title={`Join ${props.businessName}`} description={`${props.inviterName ?? 'The owner'} invited you as ${access}.`}>
            <Head title={`Join ${props.businessName}`} />

            {errors.invitation && (
                <Alert variant="destructive" className="mb-6">
                    <AlertDescription>{errors.invitation}</AlertDescription>
                </Alert>
            )}

            {state === 'register' && <RegisterForm {...props} />}
            {state === 'join' && <JoinButton url={props.url ?? ''} email={props.email ?? ''} />}
            {state === 'signIn' && (
                <div className="grid gap-4 text-sm">
                    <p className="text-muted-foreground">
                        You already have a Switch &amp; Save account with <span className="text-foreground font-medium">{props.email}</span>. Sign in
                        and you come straight back here to accept.
                    </p>
                    <Button asChild size="lg" className="h-11 w-full text-[15px] font-semibold">
                        <Link href={route('login')}>Sign in to accept</Link>
                    </Button>
                </div>
            )}
            {state === 'wrongAccount' && <WrongAccount url={props.url ?? ''} email={props.email ?? ''} signedInAs={props.signedInAs ?? ''} />}
        </AuthLayout>
    );
}

function RegisterForm({ email, name, url }: AcceptInvitationProps) {
    const { data, setData, post, processing, errors, reset } = useForm({ name: name ?? '', password: '', password_confirmation: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(url ?? '', { onFinish: () => reset('password', 'password_confirmation') });
    };

    return (
        <form onSubmit={submit} className="grid gap-5" noValidate>
            <FormField id="email" label="Email">
                <IconInput id="email" icon={Mail} type="email" value={email ?? ''} readOnly autoComplete="username" className="bg-muted/60" />
            </FormField>
            <FormField id="name" label="Your name" error={errors.name}>
                <IconInput
                    id="name"
                    icon={UserRound}
                    value={data.name}
                    autoComplete="name"
                    maxLength={255}
                    onChange={(e) => setData('name', e.target.value)}
                    aria-invalid={!!errors.name || undefined}
                    aria-describedby={errors.name ? 'name-error' : undefined}
                />
            </FormField>
            <FormField id="password" label="Choose a password" error={errors.password}>
                <PasswordInput
                    id="password"
                    autoFocus
                    autoComplete="new-password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    aria-invalid={!!errors.password || undefined}
                    aria-describedby={errors.password ? 'password-error' : undefined}
                />
            </FormField>
            <FormField id="password_confirmation" label="Confirm password">
                <PasswordInput
                    id="password_confirmation"
                    autoComplete="new-password"
                    value={data.password_confirmation}
                    onChange={(e) => setData('password_confirmation', e.target.value)}
                />
            </FormField>
            <AuthSubmit processing={processing} className="mt-1">
                Accept and create account
            </AuthSubmit>
        </form>
    );
}

function JoinButton({ url, email }: { url: string; email: string }) {
    const [processing, setProcessing] = useState(false);

    return (
        <div className="grid gap-4 text-sm">
            <p className="text-muted-foreground">
                You are signed in as <span className="text-foreground font-medium">{email}</span>.
            </p>
            <Button
                size="lg"
                className="h-11 w-full text-[15px] font-semibold"
                disabled={processing}
                aria-busy={processing || undefined}
                onClick={() => router.post(url, {}, { onStart: () => setProcessing(true), onFinish: () => setProcessing(false) })}
            >
                {processing && <LoaderCircle className="size-4 animate-spin" />}
                Accept invitation
            </Button>
        </div>
    );
}

function WrongAccount({ url, email, signedInAs }: { url: string; email: string; signedInAs: string }) {
    const [processing, setProcessing] = useState(false);

    return (
        <div className="grid gap-4 text-sm">
            <p className="text-muted-foreground">
                You are signed in as <span className="text-foreground font-medium">{signedInAs}</span>, but this invitation is for{' '}
                <span className="text-foreground font-medium">{email}</span>.
            </p>
            <Button
                size="lg"
                className="h-11 w-full text-[15px] font-semibold"
                disabled={processing}
                aria-busy={processing || undefined}
                onClick={() => router.delete(url, { onStart: () => setProcessing(true), onFinish: () => setProcessing(false) })}
            >
                {processing && <LoaderCircle className="size-4 animate-spin" />}
                Sign out and continue as {email}
            </Button>
        </div>
    );
}
