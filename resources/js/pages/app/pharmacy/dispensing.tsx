import { CashFilters } from '@/components/app/cash/cash-page';
import { CHARGE, ChargePill, chargeLabel, EXEMPTIONS, PharmacyPageLayout, shopDateTime } from '@/components/app/pharmacy/format';
import { type ChargeStatus, type DispensingProps, type DispensingRow } from '@/components/app/pharmacy/types';
import { FilterSelect } from '@/components/app/setup/fields';
import { DataTable, useTableQuery } from '@/components/shared/data-table';
import { EmptyState } from '@/components/shared/empty-state';
import { MoneyIcon } from '@/components/shared/money-icon';
import { SectionCard } from '@/components/shared/section-card';
import { StatCard, StatGrid } from '@/components/shared/stat-card';
import { money, number, shortDay, weekday } from '@/components/shared/trading/format';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { type ColumnDef } from '@tanstack/react-table';
import { BadgeCheck, ClipboardList, ShieldCheck } from 'lucide-react';
import { ukOnly } from '@/lib/country-text';

const ONLY = ['records', 'summary', 'exemptions', 'shops', 'periods', 'filters', 'options', 'charge', 'exemption'];

const columns: ColumnDef<DispensingRow>[] = [
    {
        id: 'dispensed',
        header: 'Dispensed',
        meta: { mobile: 'title' },
        cell: ({ row }) => (
            <div className="grid leading-5">
                <span className="font-medium">{shopDateTime(row.original.dispensedAt)}</span>
                <span className="text-muted-foreground text-xs">
                    {[row.original.shop, row.original.dispensedBy ? `by ${row.original.dispensedBy}` : null].filter(Boolean).join(' · ')}
                </span>
            </div>
        ),
    },
    {
        id: 'prescriber',
        header: 'Prescriber',
        meta: { mobile: 'field' },
        cell: ({ row }) => (
            <div className="grid leading-5">
                <span>{row.original.prescriber ?? '—'}</span>
                {row.original.prescriberRegistration && <span className="text-muted-foreground text-xs">{row.original.prescriberRegistration}</span>}
            </div>
        ),
    },
    {
        id: 'items',
        header: 'Items',
        meta: { align: 'right', mobile: 'field' },
        cell: ({ row }) => <span className="tabular-nums">{number(row.original.items)}</span>,
    },
    {
        id: 'status',
        header: 'Charge',
        meta: { mobile: 'aside' },
        cell: ({ row }) => (
            <div className="grid justify-items-start gap-1">
                <ChargePill status={row.original.chargeStatus} />
                {row.original.chargeStatus === 'exempt' && row.original.exemption && (
                    <span className="text-muted-foreground text-xs">{EXEMPTIONS[row.original.exemption] ?? row.original.exemption}</span>
                )}
            </div>
        ),
    },
    {
        id: 'amount',
        header: 'Charged',
        meta: { align: 'right', mobile: 'field' },
        cell: ({ row }) => <span className="tabular-nums">{row.original.chargeStatus === 'exempt' ? '—' : money(row.original.chargeAmount)}</span>,
    },
];

/** Module 5.10: dispensing records from the pharmacy's tills (read only), counted by charge, exemption, shop and period. */
export default function PharmacyDispensing(props: DispensingProps) {
    const { records, summary, exemptions, shops, periods, filters, options, charge, exemption } = props;
    const { update, loading } = useTableQuery({ only: ONLY });
    const busiest = Math.max(1, ...periods.rows.map((r) => r.records));

    return (
        <PharmacyPageLayout
            tab="dispensing"
            title="Dispensing"
            description={ukOnly(
                "Prescriptions dispensed on your pharmacy tills: NHS charges paid, exemptions and private prescriptions. Patients' details stay on the till.",
                "Prescriptions dispensed on your pharmacy tills: charges paid, exemptions and private prescriptions. Patients' details stay on the till.",
            )}
        >
            <StatGrid>
                <StatCard
                    label="Prescriptions"
                    value={number(summary.records)}
                    hint={`${number(summary.items)} items dispensed`}
                    icon={ClipboardList}
                    tone="primary"
                />
                <StatCard
                    label={chargeLabel('paid')}
                    value={number(summary.paid)}
                    hint={`${money(summary.nhsCharges)} taken`}
                    icon={MoneyIcon}
                    tone="success"
                />
                <StatCard
                    label="Exempt"
                    value={number(summary.exempt)}
                    hint={summary.records ? `${Math.round((summary.exempt / summary.records) * 100)}% of prescriptions` : 'No prescriptions'}
                    icon={ShieldCheck}
                    tone="neutral"
                />
                <StatCard
                    label="Private"
                    value={number(summary.private)}
                    hint={`${money(summary.privateCharges)} charged`}
                    icon={BadgeCheck}
                    tone="neutral"
                />
            </StatGrid>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <SectionCard title="Exemptions" description="Why exempt prescriptions were free." flush>
                    {exemptions.length === 0 ? (
                        <EmptyState size="sm" title="No exempt prescriptions" body="None in these dates." />
                    ) : (
                        <Table>
                            <TableBody>
                                {exemptions.map((e) => (
                                    <TableRow key={e.exemption}>
                                        <TableCell>{EXEMPTIONS[e.exemption] ?? e.exemption}</TableCell>
                                        <TableCell className="text-right tabular-nums">{number(e.count)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </SectionCard>
                <SectionCard title="By shop" flush>
                    {shops.length === 0 ? (
                        <EmptyState size="sm" title="Nothing dispensed" body="No prescriptions in these dates." />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Shop</TableHead>
                                    <TableHead className="text-right">Paid</TableHead>
                                    <TableHead className="text-right">Exempt</TableHead>
                                    <TableHead className="text-right">Private</TableHead>
                                    <TableHead className="text-right">Charges</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {shops.map((s) => (
                                    <TableRow key={s.shop}>
                                        <TableCell className="font-medium">{s.shop}</TableCell>
                                        <TableCell className="text-right tabular-nums">{number(s.paid)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{number(s.exempt)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{number(s.private)}</TableCell>
                                        <TableCell className="text-right tabular-nums">{money(s.charges)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </SectionCard>
            </div>

            <SectionCard title={periods.unit === 'day' ? 'By day' : 'By week'} description="Prescriptions dispensed, split by charge." flush>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{periods.unit === 'day' ? 'Day' : 'Week from'}</TableHead>
                            <TableHead className="hidden w-1/3 sm:table-cell" />
                            <TableHead className="text-right">Paid</TableHead>
                            <TableHead className="text-right">Exempt</TableHead>
                            <TableHead className="text-right">Private</TableHead>
                            <TableHead className="text-right">Total</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {periods.rows.map((p) => (
                            <TableRow key={p.period}>
                                <TableCell>{periods.unit === 'day' ? weekday(p.period) : shortDay(p.period)}</TableCell>
                                <TableCell className="hidden sm:table-cell">
                                    <div className="bg-muted h-2 overflow-hidden rounded-full" aria-hidden>
                                        <div className="bg-primary h-full rounded-full" style={{ width: `${(p.records / busiest) * 100}%` }} />
                                    </div>
                                </TableCell>
                                <TableCell className="text-right tabular-nums">{number(p.paid)}</TableCell>
                                <TableCell className="text-right tabular-nums">{number(p.exempt)}</TableCell>
                                <TableCell className="text-right tabular-nums">{number(p.private)}</TableCell>
                                <TableCell className="text-right font-medium tabular-nums">{number(p.records)}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </SectionCard>

            <DataTable
                columns={columns}
                data={records.data}
                meta={records.meta}
                onChange={update}
                loading={loading}
                searchable={false}
                filters={
                    <CashFilters filters={filters} options={options} update={update} showTill={false}>
                        <FilterSelect
                            value={charge}
                            onChange={(value) => update({ charge: value, page: undefined })}
                            all="Every charge"
                            options={(Object.keys(CHARGE) as ChargeStatus[]).map((c) => ({ value: c, label: chargeLabel(c) }))}
                            label="Filter by charge"
                        />
                        <FilterSelect
                            value={exemption}
                            onChange={(value) => update({ exemption: value, page: undefined })}
                            all="Every exemption"
                            options={Object.entries(EXEMPTIONS)
                                .filter(([value]) => value !== 'none')
                                .map(([value, label]) => ({ value, label }))}
                            label="Filter by exemption"
                        />
                    </CashFilters>
                }
                getRowId={(row) => row.id}
                empty={
                    <EmptyState
                        icon={ClipboardList}
                        title="No prescriptions dispensed"
                        body="Prescriptions dispensed on the pharmacy's tills appear here after they sync."
                    />
                }
            />
        </PharmacyPageLayout>
    );
}
