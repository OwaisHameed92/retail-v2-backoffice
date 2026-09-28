import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { cn } from '@/lib/utils';
import { Head } from '@inertiajs/react';
import { CircleCheck, Clock, type LucideIcon, TriangleAlert } from 'lucide-react';

interface DirectDebitPageProps {
    state: 'ready' | 'pending' | 'unavailable';
    businessName: string;
    supportEmail: string;
    supportPhone: string | null;
    portalUrl: string;
}

const copy: Record<DirectDebitPageProps['state'], { title: string; body: (name: string) => string; icon: LucideIcon; tone: string }> = {
    ready: {
        title: 'Your Direct Debit is set up',
        body: (name) => `Thank you. ${name} now pays Switch & Save by Direct Debit. GoCardless emails you before each collection, and we email an invoice for every payment.`,
        icon: CircleCheck,
        tone: 'bg-success-soft text-success-foreground',
    },
    pending: {
        title: 'Thanks, we are confirming it',
        body: (name) => `GoCardless is finishing the Direct Debit for ${name}. There is nothing more to do: we confirm it by email within a few minutes.`,
        icon: Clock,
        tone: 'bg-info-soft text-info-foreground',
    },
    unavailable: {
        title: 'This link cannot be used',
        body: () => 'Direct Debit setup is not available for this account right now. Please contact us and we will send you a new link.',
        icon: TriangleAlert,
        tone: 'bg-warning-soft text-warning-foreground',
    },
};

/** Where the Direct Debit email link and GoCardless' return lead (module 1.12). Public, reached by signed links only. */
export default function DirectDebitPage({ state, businessName, supportEmail, supportPhone, portalUrl }: DirectDebitPageProps) {
    const text = copy[state];
    const Icon = text.icon;

    return (
        <AuthLayout title={text.title} description={text.body(businessName)}>
            <Head title="Direct Debit" />

            <div className="flex flex-col items-center gap-6 text-center">
                <div className={cn('flex size-12 items-center justify-center rounded-full', text.tone)}>
                    <Icon className="size-6" aria-hidden />
                </div>

                <p className="text-muted-foreground text-sm">
                    Questions? Email{' '}
                    <a href={`mailto:${supportEmail}`} className="text-primary font-medium hover:underline">
                        {supportEmail}
                    </a>
                    {supportPhone && <> or call {supportPhone}</>}.
                </p>

                <Button asChild variant={state === 'unavailable' ? 'default' : 'outline'}>
                    <a href={portalUrl}>Go to your portal</a>
                </Button>
            </div>
        </AuthLayout>
    );
}
