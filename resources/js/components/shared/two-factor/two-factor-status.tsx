import { StatusBadge } from '@/components/shared/status-badge';
import { ShieldAlert, ShieldCheck } from 'lucide-react';
import { type ReactNode } from 'react';

export interface TwoFactorState {
    enabled: boolean;
    confirmedAt: string | null;
    recoveryCodesLeft: number;
}

const dateFormat = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Europe/London' });

/** The two-factor summary row on a security page: icon, state, since when, codes left, and actions on the right. */
export function TwoFactorStatus({ state, actions }: { state: TwoFactorState; actions?: ReactNode }) {
    const Icon = state.enabled ? ShieldCheck : ShieldAlert;

    return (
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-start gap-3">
                <span
                    className={`flex size-10 shrink-0 items-center justify-center rounded-full ${state.enabled ? 'bg-success-soft text-success' : 'bg-warning-soft text-warning'}`}
                >
                    <Icon className="size-5" aria-hidden />
                </span>
                <div className="grid gap-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-medium">Authenticator app</span>
                        <StatusBadge
                            status={state.enabled ? 'on' : 'off'}
                            tone={state.enabled ? 'success' : 'neutral'}
                            label={state.enabled ? 'On' : 'Off'}
                        />
                    </div>
                    <p className="text-muted-foreground text-sm">
                        {state.enabled ? (
                            <>
                                On since {state.confirmedAt ? dateFormat.format(new Date(state.confirmedAt)) : 'set-up'} ·{' '}
                                <span className={state.recoveryCodesLeft <= 2 ? 'text-warning font-medium' : undefined}>
                                    {state.recoveryCodesLeft} recovery {state.recoveryCodesLeft === 1 ? 'code' : 'codes'} left
                                </span>
                            </>
                        ) : (
                            'Sign in with your password only.'
                        )}
                    </p>
                </div>
            </div>
            {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
        </div>
    );
}
