import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { RegenerateCodesDialog } from '@/components/shared/two-factor/regenerate-codes-dialog';
import { TwoFactorStatus, type TwoFactorState } from '@/components/shared/two-factor/two-factor-status';
import AdminLayout from '@/layouts/admin-layout';
import { Head } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';

/** The signed-in admin's sign-in security: two-factor (required for every admin) and recovery codes. */
export default function AdminSecurity({ twoFactor }: { twoFactor: TwoFactorState }) {
    return (
        <AdminLayout width="narrow" breadcrumbs={[{ title: 'Your account' }, { title: 'Sign-in security' }]}>
            <Head title="Sign-in security" />

            <PageHeader
                title="Sign-in security"
                icon={ShieldCheck}
                description="Every Switch & Save admin signs in with a password and a code from an authenticator app."
            />

            <SectionCard
                title="Two-factor sign-in"
                description="Required for admins. If you lose your phone and your recovery codes, ask an owner to reset it."
            >
                <TwoFactorStatus
                    state={twoFactor}
                    actions={
                        twoFactor.enabled ? <RegenerateCodesDialog url={route('admin.security.recovery-codes')} only={['twoFactor']} /> : undefined
                    }
                />
            </SectionCard>
        </AdminLayout>
    );
}
