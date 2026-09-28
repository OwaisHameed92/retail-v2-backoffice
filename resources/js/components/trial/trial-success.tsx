import { CircleCheck } from 'lucide-react';

/** What the prospect sees after the trial request is accepted (module 1.10). */
export function TrialSuccess({ firstName, reference, message, supportEmail }: { firstName: string; reference: string; message: string; supportEmail: string }) {
    const steps = ['We call you to talk through your shop and tills.', 'We set up your account and email your licence keys.', 'You install the till software and start your 7-day trial.'];

    return (
        <div className="bg-card border-border shadow-card space-y-6 rounded-xl border p-6 sm:p-8" role="status" aria-live="polite">
            <div className="space-y-3">
                <span className="bg-success-soft text-success flex size-11 items-center justify-center rounded-full">
                    <CircleCheck className="size-6" aria-hidden />
                </span>
                <h2 className="text-foreground text-xl font-semibold tracking-[-0.01em]">Thank you{firstName ? `, ${firstName}` : ''}</h2>
                <p className="text-muted-foreground text-sm leading-6">{message}</p>
            </div>

            <div className="bg-muted/50 border-border rounded-lg border px-4 py-3">
                <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">Your reference</p>
                <p className="text-foreground mt-1 font-mono text-lg font-semibold tabular-nums">{reference}</p>
            </div>

            <div className="space-y-3">
                <h3 className="text-foreground text-sm font-semibold">What happens next</h3>
                <ol className="space-y-2.5">
                    {steps.map((step, index) => (
                        <li key={step} className="text-muted-foreground flex gap-3 text-sm leading-6">
                            <span className="bg-primary-soft text-primary flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold">
                                {index + 1}
                            </span>
                            <span>{step}</span>
                        </li>
                    ))}
                </ol>
            </div>

            <p className="text-muted-foreground text-[13px] leading-5">
                Questions in the meantime? Email{' '}
                <a className="text-primary font-medium hover:underline" href={`mailto:${supportEmail}`}>
                    {supportEmail}
                </a>{' '}
                and quote your reference.
            </p>
        </div>
    );
}
