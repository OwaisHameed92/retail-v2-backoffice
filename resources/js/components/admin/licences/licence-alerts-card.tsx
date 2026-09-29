import { formatDateTimeShort, formatRelative, plural } from '@/components/admin/licences/format';
import { type LicenceAlert } from '@/components/admin/licences/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { CheckCircle2, ShieldAlert } from 'lucide-react';
import { useState } from 'react';

interface LicenceAlertsCardProps {
    licenceId: string;
    alerts: LicenceAlert[];
    canManage: boolean;
}

function pcLabel(name: string | null, ending: string | null): string {
    if (name && ending) {
        return `${name} (${ending})`;
    }

    return name ?? (ending ? `PC ${ending}` : 'Unknown PC');
}

function AlertFacts({ alert }: { alert: LicenceAlert }) {
    const { details } = alert;
    if (alert.automatic) {
        return details.summary ? <p className="text-foreground mt-2 text-xs">{details.summary}</p> : null;
    }
    const facts: { label: string; value: string }[] = [{ label: 'PC', value: pcLabel(details.deviceName, details.deviceIdEnding) }];
    if (details.installCode) {
        facts.push({ label: 'Install code', value: details.installCode });
    }

    if (alert.type !== 'reissuedKeyUsed' && (details.boundDeviceName || details.boundDeviceIdEnding)) {
        facts.push({ label: 'Bound PC', value: pcLabel(details.boundDeviceName, details.boundDeviceIdEnding) });
    }
    if (details.retiredKeyLast4) {
        facts.push({ label: 'Old key ends', value: details.retiredKeyLast4 });
    }
    if (details.ip) {
        facts.push({ label: 'IP', value: details.ip });
    }
    if (details.appVersion) {
        facts.push({ label: 'App', value: `SSPOS ${details.appVersion}` });
    }

    return (
        <dl className="text-muted-foreground mt-2 grid gap-x-4 gap-y-1 text-xs sm:grid-cols-2">
            {facts.map((fact) => (
                <div key={fact.label} className="flex min-w-0 gap-1.5">
                    <dt className="shrink-0">{fact.label}:</dt>
                    <dd className="text-foreground min-w-0 truncate font-mono">{fact.value}</dd>
                </div>
            ))}
        </dl>
    );
}

/** "Alerts" card on the licence page: what the till API noticed (same key on two PCs, old key still used). */
export function LicenceAlertsCard({ licenceId, alerts, canManage }: LicenceAlertsCardProps) {
    const [busy, setBusy] = useState<string | null>(null);

    if (alerts.length === 0) {
        return null;
    }

    const open = alerts.filter((alert) => !alert.resolvedAt);

    const resolve = (alertId: string) => {
        setBusy(alertId);
        router.post(
            route('admin.licences.alerts.resolve', { licence: licenceId, alert: alertId }),
            {},
            { preserveScroll: true, onFinish: () => setBusy(null) },
        );
    };

    return (
        <Card className={cn(open.length > 0 && 'border-warning/40')}>
            <CardHeader className="pb-2">
                <h2 className="flex items-center gap-2 text-base font-semibold">
                    <ShieldAlert className={cn('size-4', open.length > 0 ? 'text-warning-foreground' : 'text-muted-foreground')} aria-hidden />
                    Alerts
                </h2>
                <p className="text-muted-foreground text-sm">
                    {open.length > 0
                        ? `${plural(open.length, 'open alert')} from the till API and Till health.`
                        : 'No open alerts. Recently resolved ones are listed below.'}
                </p>
            </CardHeader>
            <CardContent>
                <ul className="divide-y">
                    {alerts.map((alert) => {
                        const resolved = Boolean(alert.resolvedAt);

                        return (
                            <li
                                key={alert.id}
                                className={cn('flex flex-col gap-3 py-3 sm:flex-row sm:items-start sm:justify-between', resolved && 'opacity-70')}
                            >
                                <div className="min-w-0">
                                    <p className="text-sm font-medium">
                                        {alert.label}
                                        {alert.count > 1 && (
                                            <span className="text-muted-foreground font-normal"> · {plural(alert.count, 'time')}</span>
                                        )}
                                    </p>
                                    <p className="text-muted-foreground text-sm">{alert.help}</p>
                                    <AlertFacts alert={alert} />
                                    <p className="text-muted-foreground mt-2 text-xs tabular-nums">
                                        First {formatDateTimeShort(alert.firstSeenAt)} · last {formatRelative(alert.lastSeenAt)}
                                        {resolved &&
                                            ` · ${alert.automatic && !alert.resolvedBy ? 'cleared' : 'resolved'} ${formatRelative(alert.resolvedAt)}${alert.resolvedBy ? ` by ${alert.resolvedBy}` : ''}`}
                                    </p>
                                </div>
                                {!resolved && alert.automatic && (
                                    <span className="text-muted-foreground shrink-0 text-xs sm:pt-1">Clears itself when fixed</span>
                                )}
                                {!resolved && !alert.automatic && canManage && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="shrink-0"
                                        disabled={busy === alert.id}
                                        onClick={() => resolve(alert.id)}
                                    >
                                        <CheckCircle2 />
                                        Mark resolved
                                    </Button>
                                )}
                            </li>
                        );
                    })}
                </ul>
            </CardContent>
        </Card>
    );
}
