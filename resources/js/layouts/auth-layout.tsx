import AuthLayoutTemplate, { type AuthVariant } from '@/layouts/auth/auth-split-layout';
import { type ReactNode } from 'react';

/** Every signed-out page (log in, reset password, verify email, account on hold) uses this split layout. */
export default function AuthLayout({
    children,
    title,
    description,
    variant,
}: {
    children: ReactNode;
    title: string;
    description: ReactNode;
    variant?: AuthVariant;
}) {
    return (
        <AuthLayoutTemplate title={title} description={description} variant={variant}>
            {children}
        </AuthLayoutTemplate>
    );
}
