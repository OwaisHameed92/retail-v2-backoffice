import { daysUntil, formatDate, formatDateTimeShort, formatRelative } from '@/components/admin/licences/format';
import { LicenceActions } from '@/components/admin/licences/licence-actions';
import { LicenceActivity } from '@/components/admin/licences/licence-activity';
import { LicenceAlertsCard } from '@/components/admin/licences/licence-alerts-card';
import { CheckIn, MaskedKey } from '@/components/admin/licences/licence-columns';
import { LicenceNotes } from '@/components/admin/licences/licence-notes';
import { LicenceStatusBadge } from '@/components/admin/licences/licence-status-badge';
import { LicenceTimeline } from '@/components/admin/licences/licence-timeline';
import { type LicenceDetail, type LicenceShowProps } from '@/components/admin/licences/types';
import { InitialsAvatar } from '@/components/shared/entity-cell';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard } from '@/components/shared/stat-card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import AdminLayout from '@/layouts/admin-layout';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Ban, CalendarClock, CirclePause, Cpu, KeyRound, Layers, MonitorSmartphone, Radio } from 'lucide-react';
import { type ReactNode } from 'react';

function DetailRow({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid grid-cols-[8.5rem_minmax(0,1fr)] gap-3 py-2.5 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 break-words">{children}</dd>
        </div>
    );
}

function expiryCard(licence: LicenceDetail): { label: string; value: string; hint: string } {
    if (licence.status === 'revoked') {
        return { label: 'Expires', value: '—', hint: 'Revoked licences never expire again' };
    }
    if (!licence.endsAt) {
        return { label: 'Trial', value: 'Not started', hint: 'Starts on the first activation' };
    }
    const days = daysUntil(licence.endsAt) ?? 0;
    const hint =
        licence.status === 'grace'
            ? `Grace ends ${formatRelative(licence.graceEndsAt)}`
            : days >= 0
              ? `${formatRelative(licence.endsAt)} · then ${licence.graceDays} grace ${licence.graceDays === 1 ? 'day' : 'days'}`
              : `Ended ${formatRelative(licence.endsAt)}`;

    return { label: licence.isTrial ? 'Trial ends' : 'Paid until', value: formatDate(licence.endsAt), hint };
}

function StatusAlert({ licence }: { licence: LicenceDetail }) {
    if (licence.isRevoked) {
        return (
            <Alert variant="destructive">
                <Ban className="size-4" />
                <AlertTitle>Revoked on {formatDate(licence.revokedAt)}</AlertTitle>
                <AlertDescription>
                    {licence.revokedReason ?? 'No reason given.'} This key will never work again. Issue a new licence for the till from the tenant
                    page.
                </AlertDescription>
            </Alert>
        );
    }
    if (licence.isSuspended) {
        return (
            <Alert variant="destructive">
                <CirclePause className="size-4" />
                <AlertTitle>Suspended {licence.suspendedAt ? `on ${formatDate(licence.suspendedAt)}` : ''}</AlertTitle>
                <AlertDescription>{licence.suspendedReason ?? 'No reason given.'} The till stops trading at its next check-in.</AlertDescription>
            </Alert>
        );
    }
    if (licence.status === 'suspended' && licence.statusReason) {
        return (
            <Alert variant="destructive">
                <AlertTriangle className="size-4" />
                <AlertTitle>Locked by the account</AlertTitle>
                <AlertDescription>{licence.statusReason} The licence itself is fine; the till trades again once that is sorted.</AlertDescription>
            </Alert>
        );
    }
    if (licence.status === 'grace' || licence.status === 'expired') {
        return (
            <Alert variant="warning">
                <AlertTriangle className="size-4" />
                <AlertTitle>{licence.status === 'grace' ? 'In its grace period' : 'Expired'}</AlertTitle>
                <AlertDescription>
                    {licence.status === 'grace'
                        ? `The till locks ${formatRelative(licence.graceEndsAt)} (${formatDateTimeShort(licence.graceEndsAt)}) unless the licence is renewed.`
                        : 'The till is locked. Renew the licence to let it trade again.'}
                </AlertDescription>
            </Alert>
        );
    }

    return null;
}

export default function LicenceShow({ licence, timeline, activity, alerts, plans, can }: LicenceShowProps) {
    const expiry = expiryCard(licence);

    return (
        <AdminLayout
            breadcrumbs={[
                { title: 'Customers' },
                { title: 'Licences', href: route('admin.licences.index') },
                { title: `Key ending ${licence.keyLast4}` },
            ]}
        >
            <Head title={`Licence ${licence.keyLast4}`} />

            <PageHeader
                title={
                    <>
                        <span className="sr-only">Licence </span>
                        <MaskedKey value={licence.maskedKey} className="text-2xl" />
                    </>
                }
                status={<LicenceStatusBadge status={licence.status} reason={licence.statusReason} />}
                back={{ href: route('admin.licences.index'), label: 'Licences' }}
                media={<InitialsAvatar name={licence.company.name} shape="square" size="lg" icon={KeyRound} />}
                description={
                    <>
                        {licence.register.name}
                        {licence.register.code && ` (${licence.register.code})`} at {licence.branch.name} ·{' '}
                        <Link href={route('admin.tenants.show', licence.company.id)} className="text-primary font-medium hover:underline">
                            {licence.company.name}
                        </Link>
                    </>
                }
                actions={can.manage && <LicenceActions licence={licence} plans={plans} />}
            />

            <StatusAlert licence={licence} />

            <LicenceAlertsCard licenceId={licence.id} alerts={alerts} canManage={can.manage} />

            <div className="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-3 xl:grid-cols-5">
                <StatCard
                    label="Plan"
                    value={<span className="text-xl">{licence.plan?.name ?? '—'}</span>}
                    hint={`${licence.features.length} ${licence.features.length === 1 ? 'feature' : 'features'}${licence.plan?.archived ? ' · plan archived' : ''}`}
                    icon={Layers}
                />
                <StatCard label={expiry.label} value={<span className="text-xl">{expiry.value}</span>} hint={expiry.hint} icon={CalendarClock} />
                <StatCard
                    label="PC"
                    value={
                        <span className="block truncate text-xl">{licence.deviceName ?? (licence.deviceId ? 'Unnamed PC' : 'Not activated')}</span>
                    }
                    hint={licence.boundAt ? `Bound ${formatRelative(licence.boundAt)}` : 'Binds on first activation'}
                    icon={MonitorSmartphone}
                />
                <StatCard
                    label="Last check-in"
                    value={
                        <span className="text-xl">
                            <CheckIn at={licence.lastCheckInAt} />
                        </span>
                    }
                    hint={licence.lastCheckInAt ? formatDateTimeShort(licence.lastCheckInAt) : 'The till checks in daily'}
                    icon={Radio}
                />
                <StatCard
                    label="App version"
                    value={<span className="text-xl">{licence.lastAppVersion ? `SSPOS ${licence.lastAppVersion}` : '—'}</span>}
                    hint={[licence.os, licence.lastIp && `from ${licence.lastIp}`].filter(Boolean).join(' · ') || 'Reported at check-in'}
                    icon={Cpu}
                    className="col-span-2 lg:col-span-1"
                />
            </div>

            <div className="grid items-start gap-6 lg:grid-cols-3">
                <div className="grid gap-6 lg:col-span-2">
                    <SectionCard title="Details">
                        <dl className="-my-2.5 divide-y">
                            <DetailRow label="Licence key">
                                <MaskedKey value={licence.maskedKey} className="break-all whitespace-normal" />
                                <p className="text-muted-foreground text-xs">
                                    Only the last 4 characters are kept. Reissue the key if the owner lost it.
                                </p>
                            </DetailRow>
                            <DetailRow label="Business">
                                <Link href={route('admin.tenants.show', licence.company.id)} className="text-primary hover:underline">
                                    {licence.company.name}
                                </Link>
                            </DetailRow>
                            <DetailRow label="Till">
                                {licence.register.name}
                                {licence.register.code && <span className="text-muted-foreground font-mono"> · {licence.register.code}</span>}
                                {licence.register.isMainTill && <span className="text-muted-foreground"> · main till</span>}, {licence.branch.name}
                                <p className="text-muted-foreground text-xs">
                                    {licence.seat.position !== null
                                        ? `Till ${licence.seat.position} of ${licence.seat.allowed}`
                                        : `${licence.seat.allowed} tills allowed`}{' '}
                                    · {licence.seat.keysInUse} {licence.seat.keysInUse === 1 ? 'key' : 'keys'} in use, {licence.seat.activated}{' '}
                                    activated
                                </p>
                            </DetailRow>
                            {licence.activateBy && (
                                <DetailRow label="Activate by">
                                    <span className={new Date(licence.activateBy).getTime() < Date.now() ? 'text-destructive' : undefined}>
                                        {formatDateTimeShort(licence.activateBy)}
                                    </span>
                                    <p className="text-muted-foreground text-xs">
                                        {new Date(licence.activateBy).getTime() < Date.now()
                                            ? 'Passed: the till is told the key has expired. Extend the date or email a new key.'
                                            : `An unused key must be activated ${formatRelative(licence.activateBy)}.`}
                                    </p>
                                </DetailRow>
                            )}
                            <DetailRow label="Install">
                                {licence.deviceId ? (
                                    <>
                                        <span className="font-mono">{licence.installCode ?? '—'}</span>
                                        <p className="text-muted-foreground font-mono text-xs break-all">Install ID {licence.deviceId}</p>
                                        {licence.existingIds?.registerId && (
                                            <p className="text-muted-foreground font-mono text-xs break-all">
                                                Till’s register id {licence.existingIds.registerId}
                                            </p>
                                        )}
                                    </>
                                ) : (
                                    <span className="text-muted-foreground">Not activated</span>
                                )}
                            </DetailRow>
                            <DetailRow label="Till clock">
                                {licence.tillClockSkewSeconds === null ? (
                                    <span className="text-muted-foreground">Not reported yet</span>
                                ) : (
                                    clockSkew(licence.tillClockSkewSeconds)
                                )}
                                {licence.lastValidatedAt && (
                                    <p className="text-muted-foreground text-xs">Last validated {formatDateTimeShort(licence.lastValidatedAt)}</p>
                                )}
                            </DetailRow>
                            <DetailRow label="Till lock">
                                {licence.lock === null ? (
                                    <span className="text-muted-foreground">Not reported yet</span>
                                ) : licence.lock.locked ? (
                                    <span className="text-destructive">Locked{licence.lock.reason ? ` (${licence.lock.reason})` : ''}</span>
                                ) : (
                                    'Trading'
                                )}
                            </DetailRow>
                            <DetailRow label="Features">
                                {licence.features.length === 0 ? (
                                    <span className="text-muted-foreground">Core till only</span>
                                ) : (
                                    <ul className="flex flex-wrap gap-1.5">
                                        {licence.features.map((feature) => (
                                            <li key={feature.value} className="bg-subtle rounded-md border px-2 py-0.5 text-xs">
                                                {feature.label}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </DetailRow>
                            <DetailRow label="Grace">
                                {licence.graceDays} {licence.graceDays === 1 ? 'day' : 'days'} after the{' '}
                                {licence.isTrial || !licence.expiresAt ? 'trial' : 'paid period'} ends
                            </DetailRow>
                            <DetailRow label="First activated">
                                {licence.activatedAt ? (
                                    formatDateTimeShort(licence.activatedAt)
                                ) : (
                                    <span className="text-muted-foreground">Not yet</span>
                                )}
                            </DetailRow>
                            <DetailRow label="Issued">{formatDateTimeShort(licence.createdAt)}</DetailRow>
                        </dl>
                    </SectionCard>
                    <LicenceNotes licenceId={licence.id} notes={licence.notes} canEdit={can.manage} />
                </div>
                <LicenceTimeline events={timeline} />
            </div>

            <LicenceActivity entries={activity} />
        </AdminLayout>
    );
}

/** "2 min fast", "40 s slow", "In step" (till clock minus portal time). */
function clockSkew(seconds: number): string {
    const size = Math.abs(seconds);
    if (size < 5) return 'In step with the portal';
    const amount = size < 120 ? `${size} s` : size < 7200 ? `${Math.round(size / 60)} min` : `${Math.round(size / 3600)} h`;
    return `${amount} ${seconds > 0 ? 'fast' : 'slow'}`;
}
