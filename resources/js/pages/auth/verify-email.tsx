import { Head, useForm } from '@inertiajs/react';
import { MailCheck, Send } from 'lucide-react';
import { FormEventHandler } from 'react';

import { AuthDivider, AuthNotice, AuthSubmit } from '@/components/auth/auth-fields';
import TextLink from '@/components/text-link';
import AuthLayout from '@/layouts/auth-layout';

export default function VerifyEmail({ status }: { status?: string }) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('verification.send'));
    };

    return (
        <AuthLayout title="Verify your email" description="Check your inbox and click the link we just sent to confirm your email address.">
            <Head title="Email verification" />

            {status === 'verification-link-sent' && (
                <AuthNotice>A new verification link has been sent to the email address you provided during registration.</AuthNotice>
            )}

            <div className="bg-primary-soft text-accent-foreground mb-6 flex items-start gap-3 rounded-xl p-4 text-sm leading-6">
                <MailCheck className="mt-0.5 size-5 shrink-0" aria-hidden />
                <p>Not there? Check your spam or junk folder, or send the link again.</p>
            </div>

            <form onSubmit={submit}>
                <AuthSubmit processing={processing} icon={Send}>
                    Resend verification email
                </AuthSubmit>
            </form>

            <AuthDivider className="mt-8 mb-6" />

            <p className="text-center text-sm">
                <TextLink href={route('logout')} method="post" as="button" className="font-semibold">
                    Log out
                </TextLink>
            </p>
        </AuthLayout>
    );
}
