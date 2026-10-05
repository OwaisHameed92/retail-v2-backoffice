import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { AlertFlag, dash, formatDateTime, Money, money, movementLabel, number, STAGE_LABELS, Variance } from './format';
import { type ShiftDetailProps, type ZTotals } from './types';

const num = 'text-right tabular-nums';

function sum(values: (string | null)[]): string | null {
    const present = values.filter((v): v is string => v !== null);
    return present.length ? (present.reduce((t, v) => t + Math.round(Number(v) * 100), 0) / 100).toFixed(2) : null;
}

/** Per payment type: the till's expected, counted, card terminal and variance (counted − expected). */
export function TendersCard({ tenders }: { tenders: ShiftDetailProps['tenders'] }) {
    return (
        <SectionCard
            title="Takings by payment type"
            description="Expected is the till's own figure (sales, float, paid in and out, cashback). Negative = short."
            flush
        >
            {tenders.length === 0 ? (
                <p className="text-muted-foreground p-6 text-sm">No reconciliation yet: the till adds these when the shift is closed.</p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Payment type</TableHead>
                            <TableHead className="text-right">Expected</TableHead>
                            <TableHead className="text-right">Counted</TableHead>
                            <TableHead className="text-right">Card terminal</TableHead>
                            <TableHead className="text-right">Variance</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {tenders.map((t) => (
                            <TableRow key={t.id}>
                                <TableCell className="font-medium">
                                    {t.name}
                                    {t.cash && (
                                        <StatusPill tone="neutral" className="ml-2">
                                            Cash
                                        </StatusPill>
                                    )}
                                </TableCell>
                                <TableCell className={num}>
                                    <Money value={t.expected} />
                                </TableCell>
                                <TableCell className={num}>
                                    <Money value={t.declared} />
                                </TableCell>
                                <TableCell className={num}>
                                    <Money value={t.terminal} />
                                </TableCell>
                                <TableCell className={num}>
                                    <Variance value={t.variance} />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableCell>Total</TableCell>
                            <TableCell className={num}>{money(sum(tenders.map((t) => t.expected)))}</TableCell>
                            <TableCell className={num}>{money(sum(tenders.map((t) => t.declared)))}</TableCell>
                            <TableCell className={num}>{money(sum(tenders.map((t) => t.terminal)))}</TableCell>
                            <TableCell className={num}>
                                <Variance value={sum(tenders.map((t) => t.variance))} />
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
            )}
        </SectionCard>
    );
}

/** Each count (opening, spot, closing): expected against counted, and the notes and coins counted. */
export function CountsCard({ stages }: { stages: ShiftDetailProps['stages'] }) {
    return (
        <SectionCard title="Cash counts" description="Expected: the float for the opening count, the till's cash expected for the closing count.">
            {stages.length === 0 ? (
                <p className="text-muted-foreground text-sm">No drawer counts were sent for this shift.</p>
            ) : (
                <div className="grid gap-4">
                    {stages.map((s) => (
                        <div key={`${s.stage}-${s.at}`} className="rounded-lg border">
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
                                <div>
                                    <p className="font-medium">{STAGE_LABELS[s.stage ?? ''] ?? 'Count'}</p>
                                    <p className="text-muted-foreground text-xs">
                                        {formatDateTime(s.at)}
                                        {s.user && ` · ${s.user}`}
                                    </p>
                                </div>
                                <dl className="flex gap-6 text-sm">
                                    <div className="text-right">
                                        <dt className="text-muted-foreground text-xs">Expected</dt>
                                        <dd>
                                            <Money value={s.expected} />
                                        </dd>
                                    </div>
                                    <div className="text-right">
                                        <dt className="text-muted-foreground text-xs">Counted</dt>
                                        <dd className="font-medium">
                                            <Money value={s.counted} />
                                        </dd>
                                    </div>
                                    <div className="text-right">
                                        <dt className="text-muted-foreground text-xs">Variance</dt>
                                        <dd>
                                            <Variance value={s.variance} />
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                            <div className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 px-4 py-2 text-xs tabular-nums">
                                {s.lines.map((l, i) => (
                                    <span key={i}>
                                        {money(l.denomination)} × {number(l.count)} = <span className="text-foreground">{money(l.total)}</span>
                                    </span>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </SectionCard>
    );
}

/** The drawer ledger: float, paid in and out, drops, account payments… and no-shift cash adopted. */
export function MovementsCard({ movements, totals }: { movements: ShiftDetailProps['movements']; totals: ShiftDetailProps['movementTotals'] }) {
    return (
        <SectionCard title="Cash movements" description="Money in and out of the drawer besides sales, as the till recorded it." flush>
            {totals.length > 0 && (
                <div className="flex flex-wrap gap-2 border-b px-6 py-3">
                    {totals.map((t) => (
                        <StatusPill key={t.type ?? 'unknown'} tone={t.type === 'accountPayment' || t.type === 'customerAdvance' || t.type === 'customerAdvanceRefund' ? 'info' : 'neutral'}>
                            {movementLabel(t.type)} · {number(t.count)} · {money(t.total)}
                        </StatusPill>
                    ))}
                </div>
            )}
            {movements.length === 0 ? (
                <p className="text-muted-foreground p-6 text-sm">No paid in, paid out, drops or account payments in this shift.</p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Time</TableHead>
                            <TableHead>Type</TableHead>
                            <TableHead>Reason</TableHead>
                            <TableHead>Staff</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {movements.map((m) => (
                            <TableRow key={m.id}>
                                <TableCell className="whitespace-nowrap">{formatDateTime(m.at)}</TableCell>
                                <TableCell>
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        {movementLabel(m.type)}
                                        {m.adopted && <StatusPill tone="warning">Before the shift</StatusPill>}
                                    </div>
                                </TableCell>
                                <TableCell className="max-w-64">
                                    <span className="block truncate">{[m.reason, m.note].filter(Boolean).join(' · ') || dash}</span>
                                </TableCell>
                                <TableCell>{m.user ?? dash}</TableCell>
                                <TableCell className={num}>
                                    <Money value={m.amount} />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
        </SectionCard>
    );
}

/** The Z report's tender lines exactly as the till printed them. */
export function ZTendersTable({ totals }: { totals: ZTotals }) {
    if (!totals.readable) {
        return <p className="text-muted-foreground p-6 text-sm">The till sent no readable totals with this Z report.</p>;
    }

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Payment type</TableHead>
                    <TableHead className="text-right">Expected</TableHead>
                    <TableHead className="text-right">Declared</TableHead>
                    <TableHead className="text-right">Card terminal</TableHead>
                    <TableHead className="text-right">Variance</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {totals.tenders.map((t, i) => (
                    <TableRow key={t.paymentTypeId ?? i}>
                        <TableCell className="font-medium">
                            <div className="flex flex-wrap items-center gap-2">
                                {t.name}
                                {t.exceedsThreshold && <AlertFlag />}
                            </div>
                        </TableCell>
                        <TableCell className={num}>
                            <Money value={t.expected} />
                        </TableCell>
                        <TableCell className={num}>
                            <Money value={t.declared} />
                        </TableCell>
                        <TableCell className={num}>
                            <Money value={t.terminal} />
                        </TableCell>
                        <TableCell className={num}>
                            <Variance value={t.variance} />
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
            <TableFooter>
                <TableRow>
                    <TableCell colSpan={4}>Variance total{totals.alertOver !== null && ` · alert over ${money(totals.alertOver)}`}</TableCell>
                    <TableCell className={num}>
                        <Variance value={totals.variance} />
                    </TableCell>
                </TableRow>
            </TableFooter>
        </Table>
    );
}
