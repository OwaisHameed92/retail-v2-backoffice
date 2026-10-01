import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/auth-layout';
import { cn } from '@/lib/utils';
import { Head, router } from '@inertiajs/react';
import { BellOff, CircleCheck, LoaderCircle, TriangleAlert } from 'lucide-react';
import { useState } from 'react';

interface UnsubscribeProps {
    state: 'confirm' | 'done' | 'unavailable';
    business: string;
    what: string;
    /** The signed URL to post to (confirm state only). */
    action: string | null;
    settingsUrl: string;
}

/** Where the "Stop these emails" link of an alert email leads (module 7.8). Public, reached by signed links only. */
export default function Unsubscribe({ state, business, what, action, settingsUrl }: UnsubscribeProps) {
    const [processing, setProcessing] = useState(false);

    const copy = {
        confirm: {
            title: 'Stop these emails?',
            body: `${what} emails from ${business} will stop. You can turn them back on at any time in your notification settings.`,
            icon: BellOff,
            tone: 'bg-info-soft text-info-foreground',
        },
        done: {
            title: 'You will not get these emails any more',
            body: `${what} emails from ${business} are now off. To change your mind, open your notification settings.`,
            icon: CircleCheck,
            tone: 'bg-success-soft text-success-foreground',
        },
        unavailable: {
            title: 'This link cannot be used',
            body: 'You are no longer a member of this business, so there is nothing to stop. Contact the owner if you still get emails.',
            icon: TriangleAlert,
            tone: 'bg-warning-soft text-warning-foreground',
        },
    }[state];
    const Icon = copy.icon;

    const confirm = () => {
        if (!action) {
            return;
        }
        setProcessing(true);
        router.post(action, {}, { onFinish: () => setProcessing(false) });
    };

    return (
        <AuthLayout title={copy.title} description={copy.body}>
            <Head title="Alert emails" />

            <div className="flex flex-col items-center gap-6 text-center">
                <div className={cn('flex size-12 items-center justify-center rounded-full', copy.tone)}>
                    <Icon className="size-6" aria-hidden />
                </div>

                <div className="flex w-full flex-col gap-2 sm:flex-row sm:justify-center">
                    {state === 'confirm' && (
                        <Button onClick={confirm} disabled={processing}>
                            {processing && <LoaderCircle className="animate-spin" />}
                            Stop these emails
                        </Button>
                    )}
                    <Button asChild variant="outline">
                        <a href={settingsUrl}>Notification settings</a>
                    </Button>
                </div>
            </div>
        </AuthLayout>
    );
}
