import { EmptyState } from '@/components/shared/empty-state';
import { SectionCard } from '@/components/shared/section-card';
import { money, number, share } from '@/components/shared/trading/format';
import { type TenderRow, type VatRow } from '@/components/shared/trading/types';
import { ChartTooltipBox, toneVar, type ChartTone } from '@/components/shared/trend-chart';
import { CreditCard, Percent } from 'lucide-react';
import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';

const tones: ChartTone[] = ['primary', 'info', 'violet', 'warning', 'success', 'danger'];

/** Takings by payment type (DASHBOARD.md §2.3): a donut and a list with share, payments and refunds paid out. */
export function TenderMixCard({ tenders }: { tenders: TenderRow[] }) {
    const total = tenders.reduce((sum, t) => sum + Math.max(0, Number(t.amount)), 0);
    const refunds = tenders.reduce((sum, t) => sum + Number(t.refunds), 0);

    return (
        <SectionCard title="Tender mix" description="Takings by payment type, net of refunds">
            {tenders.length === 0 ? (
                <EmptyState icon={CreditCard} title="No payments yet" body="Cash, card and other tenders show here once sales arrive." size="sm" />
            ) : (
                <div className="flex flex-col items-center gap-5 sm:flex-row sm:items-center">
                    <div className="relative size-40 shrink-0" role="img" aria-label="Takings by payment type">
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie
                                    data={tenders.map((t) => ({ name: t.name, value: Math.max(0, Number(t.amount)) }))}
                                    dataKey="value"
                                    nameKey="name"
                                    innerRadius="68%"
                                    outerRadius="100%"
                                    paddingAngle={tenders.length > 1 ? 2 : 0}
                                    stroke="var(--card)"
                                    isAnimationActive={false}
                                >
                                    {tenders.map((t, i) => (
                                        <Cell key={t.paymentTypeId} fill={toneVar[tones[i % tones.length]]} />
                                    ))}
                                </Pie>
                                <Tooltip
                                    content={({ active, payload }) =>
                                        active && payload?.[0] ? (
                                            <ChartTooltipBox
                                                rows={[
                                                    {
                                                        label: String(payload[0].name ?? ''),
                                                        value: money(Number(payload[0].value ?? 0)),
                                                        tone: tones[
                                                            Math.max(
                                                                0,
                                                                tenders.findIndex((t) => t.name === payload[0].name),
                                                            ) % tones.length
                                                        ],
                                                    },
                                                ]}
                                            />
                                        ) : null
                                    }
                                />
                            </PieChart>
                        </ResponsiveContainer>
                        <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                            <span className="text-muted-foreground text-[11px]">Takings</span>
                            <span className="text-sm font-semibold tabular-nums">{money(total)}</span>
                        </div>
                    </div>
                    <ul className="w-full min-w-0 flex-1 divide-y text-sm">
                        {tenders.map((t, i) => (
                            <li key={t.paymentTypeId} className="flex items-center gap-3 py-2">
                                <span
                                    className="size-2.5 shrink-0 rounded-full"
                                    style={{ background: toneVar[tones[i % tones.length]] }}
                                    aria-hidden
                                />
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate font-medium">{t.name}</span>
                                    <span className="text-muted-foreground block text-xs tabular-nums">
                                        {number(t.payments)} {t.payments === 1 ? 'payment' : 'payments'}
                                        {Number(t.refunds) !== 0 && ` · ${money(t.refunds)} refunded`}
                                    </span>
                                </span>
                                <span className="text-right tabular-nums">
                                    <span className="block font-medium">{money(t.amount)}</span>
                                    <span className="text-muted-foreground block text-xs">{share(t.amount, total).toFixed(1)}%</span>
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            {refunds !== 0 && <p className="text-muted-foreground mt-3 text-xs">Refunds paid out: {money(refunds)}</p>}
        </SectionCard>
    );
}

/** VAT by rate (DASHBOARD.md §2.3, §5.5): net, VAT and gross per rate, net of refunds, with a total row. */
export function VatCard({ rows }: { rows: VatRow[] }) {
    const sum = (key: 'net' | 'vat' | 'gross') => rows.reduce((total, r) => total + Math.round(Number(r[key]) * 100), 0) / 100;

    return (
        <SectionCard title="VAT by rate" description="Net of refunds, as on the till's VAT summary" flush className="min-w-0">
            {rows.length === 0 ? (
                <EmptyState icon={Percent} title="No VAT yet" body="Standard, reduced and zero-rated sales show here." size="sm" />
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-muted-foreground border-b text-left text-xs">
                                <th className="px-5 py-2.5 font-medium sm:px-6">Rate</th>
                                <th className="px-3 py-2.5 text-right font-medium">Net</th>
                                <th className="px-5 py-2.5 text-right font-medium sm:px-3">VAT</th>
                                <th className="hidden px-6 py-2.5 text-right font-medium sm:table-cell">Gross</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {rows.map((r) => (
                                <tr key={`${r.vatRateId}-${r.percentage}`}>
                                    <td className="px-5 py-2.5 sm:px-6">
                                        <span className="font-medium">{r.code || '—'}</span>
                                        <span className="text-muted-foreground ml-2 tabular-nums">
                                            {Number(r.percentage).toLocaleString('en-GB')}%
                                        </span>
                                    </td>
                                    <td className="px-3 py-2.5 text-right tabular-nums">{money(r.net)}</td>
                                    <td className="px-5 py-2.5 text-right tabular-nums sm:px-3">{money(r.vat)}</td>
                                    <td className="hidden px-6 py-2.5 text-right tabular-nums sm:table-cell">{money(r.gross)}</td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="bg-subtle border-t font-semibold">
                                <td className="px-5 py-2.5 sm:px-6">Total</td>
                                <td className="px-3 py-2.5 text-right tabular-nums">{money(sum('net'))}</td>
                                <td className="px-5 py-2.5 text-right tabular-nums sm:px-3">{money(sum('vat'))}</td>
                                <td className="hidden px-6 py-2.5 text-right tabular-nums sm:table-cell">{money(sum('gross'))}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            )}
        </SectionCard>
    );
}
