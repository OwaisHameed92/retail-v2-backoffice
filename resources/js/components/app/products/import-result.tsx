import { SectionCard } from '@/components/shared/section-card';
import { StatusBadge } from '@/components/shared/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatMoney } from './fields';
import { type ImportDetail } from './import-types';

const number = new Intl.NumberFormat('en-GB');

/** The first rows as the import will read them. */
export function ImportSample({ detail }: { detail: ImportDetail }) {
    const rows = detail.preview?.sample ?? [];

    return (
        <SectionCard title="First rows" description="How the first rows of your file will be read." flush>
            <div className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-16">Line</TableHead>
                            <TableHead>Product</TableHead>
                            <TableHead>Department</TableHead>
                            <TableHead className="text-right">Price</TableHead>
                            <TableHead>Result</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow key={row.line}>
                                <TableCell className="text-muted-foreground tabular-nums">{row.line}</TableCell>
                                <TableCell>
                                    <p className="font-medium">{row.name ?? '—'}</p>
                                    <p className="text-muted-foreground font-mono text-xs">{[row.barcode, row.sku].filter(Boolean).join(' · ')}</p>
                                </TableCell>
                                <TableCell>
                                    <p>{row.department ?? '—'}</p>
                                    {row.category && <p className="text-muted-foreground text-xs">{row.category}</p>}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">{row.sellPrice ? formatMoney(row.sellPrice) : '—'}</TableCell>
                                <TableCell>
                                    <StatusBadge
                                        status={row.action}
                                        label={row.action === 'create' ? 'New' : row.action === 'update' ? 'Update' : 'Skipped'}
                                        tone={row.action === 'skip' ? 'danger' : row.action === 'create' ? 'success' : 'info'}
                                    />
                                    {row.errors.length > 0 && <p className="text-danger-foreground mt-1 max-w-80 text-xs">{row.errors.join(' ')}</p>}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </SectionCard>
    );
}

/** Rows that were (or will be) left out, with why. */
export function ImportErrors({ detail, title }: { detail: ImportDetail; title: string }) {
    if (detail.errors.length === 0) {
        return null;
    }

    return (
        <SectionCard
            title={title}
            description={
                detail.errorsTruncated
                    ? `The first ${number.format(detail.errors.length)} are listed. Fix them in your file and upload it again.`
                    : 'Fix them in your file and upload it again: rows already imported are left as they are.'
            }
            flush
        >
            <ul className="max-h-[28rem] divide-y overflow-y-auto">
                {detail.errors.map((error) => (
                    <li key={error.row} className="flex gap-4 px-5 py-2.5 text-sm">
                        <span className="text-muted-foreground w-16 shrink-0 tabular-nums">Line {error.row}</span>
                        <span>{error.messages.join(' ')}</span>
                    </li>
                ))}
            </ul>
        </SectionCard>
    );
}
