import { deviceColumns } from '@/components/admin/devices/device-columns';
import { DeviceFilters, DEVICES_ONLY } from '@/components/admin/devices/device-filters';
import { devicesQuery, deviceTitle, statusFitsKind } from '@/components/admin/devices/format';
import { type DeviceKind, type DeviceRow, type DevicesIndexProps } from '@/components/admin/devices/types';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { DataTable } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { PageTabs } from '@/components/shared/page-tabs';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Button } from '@/components/ui/button';
import AdminLayout from '@/layouts/admin-layout';
import { formatNumber } from '@/lib/country';
import { Head, Link, router } from '@inertiajs/react';
import { CalendarX2, History, Monitor, MonitorSmartphone, Radio, SearchX, Smartphone, X } from 'lucide-react';
import { useMemo, useState } from 'react';

const count = (n: number) => <span className="tabular-nums">{formatNumber(n)}</span>;

export default function DevicesIndex({ devices, summary, filters, statuses, versions, clockSkewSeconds, can }: DevicesIndexProps) {
    // The row stays set while the dialog closes, so its text does not change mid-animation.
    const [releasing, setReleasing] = useState<DeviceRow | null>(null);
    const [releaseOpen, setReleaseOpen] = useState(false);
    const columns = useMemo(
        () =>
            deviceColumns({
                canRelease: can.release,
                onRelease: (row) => {
                    setReleasing(row);
                    setReleaseOpen(true);
                },
                clockSkewSeconds,
            }),
        [can.release, clockSkewSeconds],
    );
    const href = (changes: Record<string, string | number | null>) => route('admin.devices.index', devicesQuery(filters, devices.meta, changes));
    const kindHref = (kind: DeviceKind | null) =>
        href({
            kind,
            status: statusFitsKind(filters.status, kind, statuses) ? filters.status : null,
            old: kind === 'handheld' ? null : filters.old ? 1 : null,
        });
    const fresh = (changes: Record<string, string | number | null>) => route('admin.devices.index', changes as Record<string, string | number>);
    const empty = summary.tills === 0 && summary.handhelds === 0;

    const release = () =>
        new Promise<void>((resolve) => {
            if (!releasing?.licenceId) {
                resolve();

                return;
            }
            router.post(route('admin.licences.release', releasing.licenceId), {}, { preserveScroll: true, onFinish: () => resolve() });
        });

    return (
        <AdminLayout>
            <Head title="Devices" />

            <PageHeader
                title="Devices"
                description={
                    empty
                        ? 'Every till and handheld of every business, in one list.'
                        : `${formatNumber(summary.tills)} ${summary.tills === 1 ? 'till' : 'tills'} and ${formatNumber(summary.handhelds)} signed-in ${summary.handhelds === 1 ? 'handheld' : 'handhelds'} across every business. Find a customer's device by business, shop, till, install ID or key ending.`
                }
                tabs={
                    <PageTabs
                        label="Device kinds"
                        tabs={[
                            { label: 'All devices', href: kindHref(null), active: filters.kind === null, count: summary.tills + summary.handhelds },
                            { label: 'Tills', href: kindHref('till'), active: filters.kind === 'till', count: summary.tills },
                            { label: 'Handhelds', href: kindHref('handheld'), active: filters.kind === 'handheld', count: summary.handhelds },
                        ]}
                    />
                }
            />

            <StatGrid className="lg:grid-cols-3 xl:grid-cols-5">
                <StatCard
                    label="Tills"
                    value={count(summary.tills)}
                    hint="Live licences"
                    icon={Monitor}
                    tone="primary"
                    href={fresh({ kind: 'till' })}
                />
                <StatCard
                    label="Live today"
                    value={count(summary.liveToday)}
                    hint={summary.tills > 0 ? `of ${formatNumber(summary.tills)} tills, since midnight` : 'No tills yet'}
                    icon={Radio}
                    tone="success"
                />
                <StatCard
                    label={`Not seen ${summary.notSeenDays}+ days`}
                    value={count(summary.notSeen)}
                    hint="No check-in or sync"
                    icon={CalendarX2}
                    tone={summary.notSeen > 0 ? 'danger' : 'neutral'}
                    href={fresh({ kind: 'till', unseen: 1 })}
                />
                <StatCard
                    label="On old versions"
                    value={count(summary.oldVersion)}
                    hint={summary.gate ? `Older than ${summary.gate}` : 'No versions reported yet'}
                    icon={History}
                    tone={summary.oldVersion > 0 ? 'warning' : 'neutral'}
                    href={fresh({ old: 1, ...(filters.below ? { below: filters.below } : {}) })}
                />
                <StatCard
                    label="Handhelds"
                    value={count(summary.handhelds)}
                    hint="Signed in to the PDA app"
                    icon={Smartphone}
                    tone="neutral"
                    href={fresh({ kind: 'handheld' })}
                />
            </StatGrid>

            <DataTable
                columns={columns}
                data={devices.data}
                meta={devices.meta}
                only={DEVICES_ONLY}
                searchPlaceholder="Search business, shop, till, install ID or key ending"
                filters={<DeviceFilters filters={filters} statuses={statuses} versions={versions} />}
                getRowId={(row) => row.id}
                onRowClick={(row) =>
                    router.visit(row.licenceId ? route('admin.licences.show', row.licenceId) : route('admin.tenants.show', row.company.id))
                }
                empty={
                    empty ? (
                        <EmptyState
                            icon={MonitorSmartphone}
                            title="No devices yet"
                            body="Tills appear here when a business gets its licences, and handhelds when someone signs in to the PDA app."
                        />
                    ) : (
                        <EmptyState
                            icon={SearchX}
                            tone="neutral"
                            title="No devices match"
                            body={
                                filters.old && filters.gate
                                    ? `No till matches these filters on a version older than ${filters.gate}.`
                                    : 'Try another search, or clear the filters to see every device.'
                            }
                            action={
                                <Button variant="outline" asChild>
                                    <Link href={route('admin.devices.index')}>
                                        <X />
                                        Clear filters
                                    </Link>
                                </Button>
                            }
                        />
                    )
                }
            />

            <ConfirmDialog
                open={releaseOpen}
                onOpenChange={setReleaseOpen}
                title={releasing ? `Release key …${releasing.keyLast4} from its PC?` : 'Release this key?'}
                description={
                    releasing
                        ? `${releasing.deviceName ?? 'The current PC'} (${deviceTitle(releasing)} at ${releasing.company.name}) locks at its next check-in (“released”). The same key can then be activated on another PC.`
                        : undefined
                }
                confirmLabel="Release"
                destructive
                onConfirm={release}
            />
        </AdminLayout>
    );
}
