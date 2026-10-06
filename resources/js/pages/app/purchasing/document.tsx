import {
    cost,
    formatDateTime,
    formatDay,
    KIND_LABELS,
    KIND_SINGULAR,
    money,
    PurchasingStatus,
    qty,
    RETURN_REASONS,
} from '@/components/app/purchasing/format';
import { LinkedCard, ProductCell, Totals } from '@/components/app/purchasing/parts';
import { type DocumentShowProps } from '@/components/app/purchasing/types';
import { DescriptionList } from '@/components/shared/description-list';
import { EmptyState } from '@/components/shared/empty-state';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { taxText } from '@/lib/country';
import { cn } from '@/lib/utils';
import { Head } from '@inertiajs/react';
import { Eye, ListX } from 'lucide-react';
import { type ReactNode } from 'react';

const MONEY_KEYS = ['net', 'vat'];
const COST_KEYS = ['unitCost'];

function cell(key: string, value: unknown): ReactNode {
    if (value === null || value === undefined || value === '') {
        return <span className="text-muted-foreground">—</span>;
    }
    if (MONEY_KEYS.includes(key)) {
        return money(String(value));
    }
    if (COST_KEYS.includes(key)) {
        return cost(String(value));
    }
    if (key === 'reason') {
        return RETURN_REASONS[String(value)] ?? String(value);
    }
    if (key === 'damaged') {
        const n = Number(value);

        return <span className={cn(n > 0 ? 'text-warning-foreground font-medium' : 'text-muted-foreground')}>{n > 0 ? qty(n) : '—'}</span>;
    }

    return qty(String(value));
}

function fact(format: DocumentShowProps['facts'][number]['format'], value: string): ReactNode {
    switch (format) {
        case 'date':
            return formatDay(value);
        case 'datetime':
            return formatDateTime(value);
        case 'money':
            return <span className="tabular-nums">{money(value)}</span>;
        default:
            return value;
    }
}

/** One delivery (GRN), supplier invoice, credit note or purchase return (module 5.2), read only: the shop's own record. */
export default function PurchasingDocument({ kind, document, facts, columns, lines, totals, links }: DocumentShowProps) {
    const singular = KIND_SINGULAR[kind];

    return (
        <AppLayout>
            <Head title={`${singular} ${document.reference}`} />

            <PageHeader
                title={document.reference}
                back={{ href: route('app.purchasing.index', kind), label: KIND_LABELS[kind] }}
                status={document.status ? <PurchasingStatus status={document.status} /> : undefined}
                description={[singular, document.supplier, document.shop, document.date ? formatDay(document.date) : null]
                    .filter(Boolean)
                    .join(' · ')}
                actions={
                    <div className="text-right">
                        <p className="text-muted-foreground text-xs">{taxText('Total inc. VAT')}</p>
                        <p className="text-2xl font-semibold tabular-nums">{money(totals.gross)}</p>
                    </div>
                }
            />

            <Alert>
                <Eye />
                <AlertDescription>Kept at the shop: this is its till's record, so it is read only here.</AlertDescription>
            </Alert>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3 lg:items-start">
                <SectionCard title="Lines" flush className="lg:col-span-2">
                    {lines.length === 0 ? (
                        <EmptyState icon={ListX} title="No lines" body="The shop recorded this without product lines." className="py-8" />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Product</TableHead>
                                    {columns.map((c) => (
                                        <TableHead key={c.key} className={cn(c.key !== 'reason' && 'text-right')}>
                                            {c.label}
                                        </TableHead>
                                    ))}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {lines.map((line) => (
                                    <TableRow key={line.id}>
                                        <TableCell>
                                            <ProductCell product={line.product} note={line.note} flag={line.flag} />
                                        </TableCell>
                                        {columns.map((c) => (
                                            <TableCell key={c.key} className={cn(c.key !== 'reason' && 'text-right tabular-nums')}>
                                                {cell(c.key, line[c.key])}
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                    <Totals net={totals.net} vat={totals.vat} gross={totals.gross} />
                </SectionCard>

                <div className="grid gap-4">
                    <SectionCard title="Details">
                        <DescriptionList
                            layout="rows"
                            items={[
                                { label: 'Shop', value: document.shop },
                                { label: 'Supplier', value: document.supplier },
                                ...facts.map((f) => ({ label: f.label, value: fact(f.format, f.value) })),
                                ...(document.note ? [{ label: 'Note', value: document.note }] : []),
                            ]}
                        />
                    </SectionCard>
                    <LinkedCard links={links} />
                </div>
            </div>
        </AppLayout>
    );
}
