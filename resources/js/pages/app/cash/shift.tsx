import { AlertFlag, formatDateTime, Money, money, number, ShiftStatus, Variance } from '@/components/app/cash/format';
import { CountsCard, MovementsCard, TendersCard, ZTendersTable } from '@/components/app/cash/shift-sections';
import { type ShiftDetailProps } from '@/components/app/cash/types';
import { DescriptionList } from '@/components/shared/description-list';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Info, Receipt, Scale, Wallet } from 'lucide-react';

const MODES: Record<string, string> = { perTill: 'One drawer per till', perCashier: 'One drawer per cashier', floatingCashier: 'Floating cashier' };

/** One shift (module 5.4): read only, every figure as the till sent it. */
export default function CashShift({ shift, tenders, movements, movementTotals, stages, z }: ShiftDetailProps) {
    const cashExpected = stages.find((s) => s.stage === 'close')?.expected ?? null;
    const where = [shift.shop, shift.till].filter(Boolean).join(' · ');
    const adopted = movements.filter((m) => m.adopted).length;

    return (
        <AppLayout>
            <Head title={`Shift ${formatDateTime(shift.openedAt)}`} />
            <PageHeader
                title={`Shift on ${shift.till ?? 'unknown till'}`}
                back={{ href: route('app.cash.index'), label: 'Cash and Z' }}
                status={<ShiftStatus status={shift.status} />}
                description={`${where}${where ? ' · ' : ''}opened ${formatDateTime(shift.openedAt)}${shift.closedAt ? ` · closed ${formatDateTime(shift.closedAt)}` : ''}`}
                actions={
                    z && (
                        <Button variant="outline" asChild>
                            <Link href={route('app.cash.z.show', z.id)}>
                                <Receipt />Z {z.sequenceNo}
                            </Link>
                        </Button>
                    )
                }
            />

            {shift.status === 'open' && (
                <Alert variant="info">
                    <Info />
                    <AlertDescription>This shift is still open. Expected, counted and variance arrive when the till closes it.</AlertDescription>
                </Alert>
            )}
            {adopted > 0 && (
                <Alert variant="warning">
                    <Info />
                    <AlertDescription>
                        {number(adopted)} cash {adopted === 1 ? 'movement was' : 'movements were'} taken before this shift opened (no shift was open)
                        and the till added {adopted === 1 ? 'it' : 'them'} to this shift.
                    </AlertDescription>
                </Alert>
            )}

            <StatGrid>
                <StatCard label="Opening float" value={<Money value={shift.float} />} icon={Wallet} tone="neutral" />
                <StatCard
                    label="Cash expected"
                    value={<Money value={cashExpected} />}
                    hint="At close, the till's figure"
                    icon={Wallet}
                    tone="primary"
                />
                <StatCard
                    label="Variance"
                    value={<Variance value={shift.variance} />}
                    hint="All payment types · negative = short"
                    icon={Scale}
                    tone={shift.variance !== null && Number(shift.variance) < 0 ? 'danger' : 'success'}
                />
                <StatCard label="Sales in this shift" value={number(shift.salesCount)} hint={money(shift.salesTotal)} icon={Receipt} tone="primary" />
            </StatGrid>

            <div className="grid gap-4 lg:grid-cols-3 lg:items-start">
                <div className="grid gap-4 lg:col-span-2">
                    <TendersCard tenders={tenders} />
                    <CountsCard stages={stages} />
                    <MovementsCard movements={movements} totals={movementTotals} />
                </div>
                <div className="grid gap-4">
                    <SectionCard title="Who and when">
                        <DescriptionList
                            layout="rows"
                            items={[
                                { label: 'Opened', value: formatDateTime(shift.openedAt) },
                                { label: 'Opened by', value: shift.openedBy },
                                { label: 'Closed', value: shift.closedAt ? formatDateTime(shift.closedAt) : null },
                                { label: 'Closed by', value: shift.closedBy },
                                { label: 'Drawer owner', value: shift.drawerOwner },
                                { label: 'Mode', value: shift.mode ? (MODES[shift.mode] ?? shift.mode) : null },
                                ...(shift.overrideBy || shift.overrideReason
                                    ? [{ label: 'Manager override', value: [shift.overrideBy, shift.overrideReason].filter(Boolean).join(' · ') }]
                                    : []),
                                ...(shift.notes ? [{ label: 'Close notes', value: shift.notes, wide: true }] : []),
                            ]}
                        />
                    </SectionCard>
                    {z && (
                        <SectionCard
                            title={`Z report ${z.sequenceNo}`}
                            description={z.printedAt ? `Printed ${formatDateTime(z.printedAt)}` : 'Not printed'}
                            actions={z.totals.warning ? <AlertFlag label="Till alert" /> : undefined}
                            flush
                        >
                            <ZTendersTable totals={z.totals} />
                        </SectionCard>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
