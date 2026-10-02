import { AnomalyStatusBadge, formatDateTime, formatDay, historyLabel, SeverityBadge } from '@/components/app/anomalies/format';
import { StatusActions } from '@/components/app/anomalies/status-actions';
import { type AnomalyShowProps } from '@/components/app/anomalies/types';
import { DescriptionList } from '@/components/shared/description-list';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { Timeline } from '@/components/shared/timeline';
import { showToast } from '@/components/shared/toaster';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { sendJson } from '@/lib/http';
import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, Check, LoaderCircle, RotateCcw, Sparkles, X } from 'lucide-react';
import { useState } from 'react';

const dash = <span className="text-muted-foreground">—</span>;

/** One finding (module 6.6): what was seen against the usual figures, where to look, and what was decided. */
export default function AnomalyShow({ anomaly, history, canManage, canExplain }: AnomalyShowProps) {
    const [explanation, setExplanation] = useState<string | null>(anomaly.explanation);
    const [explaining, setExplaining] = useState(false);
    const [explainNote, setExplainNote] = useState<string | null>(null);
    const hasUsual = anomaly.facts.some((f) => f.usual !== null);
    const hasPeers = anomaly.facts.some((f) => f.peers !== null);

    const explain = async () => {
        setExplaining(true);
        const result = await sendJson<{ text: string | null; message: string | null }>('POST', route('app.anomalies.explain', anomaly.id));
        setExplaining(false);

        if (!result.ok) {
            showToast(result.message ?? 'Something went wrong.', 'error');
            return;
        }

        setExplanation(result.data?.text ?? null);
        setExplainNote(result.data?.message ?? null);
    };

    return (
        <AppLayout>
            <Head title={anomaly.title} />
            <PageHeader
                title={anomaly.title}
                status={<SeverityBadge severity={anomaly.severity} />}
                back={{ href: route('app.anomalies.index'), label: 'Unusual activity' }}
                description={`${anomaly.kindLabel} · ${anomaly.shop} · ${formatDay(anomaly.tradingDay)}`}
                actions={canManage ? <StatusActions anomaly={anomaly} size="default" /> : undefined}
            />
            <div className="grid gap-6 lg:grid-cols-3">
                <div className="grid content-start gap-6 lg:col-span-2">
                    <SectionCard title="What was found">
                        <p className="text-sm leading-6">{anomaly.summary}</p>
                    </SectionCard>

                    <SectionCard title="The figures" description="This period against the usual over the last 8 weeks." flush>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="pl-5">Measure</TableHead>
                                    <TableHead className="text-right">This time</TableHead>
                                    {hasUsual && <TableHead className="text-right">Usual</TableHead>}
                                    {hasPeers && <TableHead className="pr-5 text-right">Rest of the team</TableHead>}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {anomaly.facts.map((fact) => (
                                    <TableRow key={fact.label}>
                                        <TableCell className="pl-5">{fact.label}</TableCell>
                                        <TableCell className="text-right font-medium tabular-nums">{fact.value}</TableCell>
                                        {hasUsual && <TableCell className="text-right tabular-nums">{fact.usual ?? dash}</TableCell>}
                                        {hasPeers && <TableCell className="pr-5 text-right tabular-nums">{fact.peers ?? dash}</TableCell>}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </SectionCard>

                    {(canExplain || explanation) && (
                        <SectionCard
                            title="In plain words"
                            description="Written by AI from the figures above only; every number is checked against them."
                            actions={
                                canExplain && !explanation ? (
                                    <Button size="sm" variant="outline" onClick={explain} disabled={explaining}>
                                        {explaining ? (
                                            <LoaderCircle className="size-4 animate-spin" aria-hidden />
                                        ) : (
                                            <Sparkles className="size-4" aria-hidden />
                                        )}
                                        Explain
                                    </Button>
                                ) : undefined
                            }
                        >
                            {explanation ? (
                                <p className="text-sm leading-6">{explanation}</p>
                            ) : (
                                <p className="text-muted-foreground text-sm">
                                    {explainNote ?? 'Ask for a short explanation of what this could mean and what to check first.'}
                                </p>
                            )}
                        </SectionCard>
                    )}
                </div>

                <div className="grid content-start gap-6">
                    <SectionCard title="Where to look">
                        {anomaly.links.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Your role cannot open the pages behind this finding.</p>
                        ) : (
                            <ul className="grid gap-1">
                                {anomaly.links.map((link) => (
                                    <li key={link.href}>
                                        <Link
                                            href={link.href}
                                            className="text-primary inline-flex items-center gap-1.5 text-sm font-medium hover:underline"
                                        >
                                            {link.label}
                                            <ArrowUpRight className="size-3.5" aria-hidden />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </SectionCard>

                    <SectionCard title="Details">
                        <DescriptionList
                            layout="rows"
                            items={[
                                { label: 'Status', value: <AnomalyStatusBadge status={anomaly.status} /> },
                                { label: 'Shop', value: anomaly.shop },
                                ...(anomaly.subjectName ? [{ label: 'Staff member', value: anomaly.subjectName }] : []),
                                { label: 'From', value: formatDateTime(anomaly.periodStart) },
                                { label: 'To', value: formatDateTime(anomaly.periodEnd) },
                                { label: 'Found', value: formatDateTime(anomaly.detectedAt) },
                                { label: 'Times seen', value: String(anomaly.occurrences) },
                                ...(anomaly.statusReason ? [{ label: 'Reason', value: anomaly.statusReason }] : []),
                            ]}
                        />
                    </SectionCard>

                    <SectionCard title="History">
                        <Timeline
                            items={history.map((h) => ({
                                id: h.id,
                                icon: h.action === 'anomaly.dismissed' ? X : h.action === 'anomaly.reopened' ? RotateCcw : Check,
                                tone: h.action === 'anomaly.dismissed' ? 'neutral' : 'primary',
                                title: (
                                    <>
                                        <strong>{h.by}</strong> {historyLabel(h.action)}
                                    </>
                                ),
                                time: formatDateTime(h.at),
                                body: h.reason ?? undefined,
                            }))}
                            emptyTitle="No changes yet"
                            emptyBody="Acknowledging, dismissing and reopening are kept here."
                        />
                    </SectionCard>
                </div>
            </div>
        </AppLayout>
    );
}
