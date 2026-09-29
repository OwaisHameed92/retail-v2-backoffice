import { SectionCard } from '@/components/shared/section-card';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { useState } from 'react';
import { type FieldComparison } from './types';

function Value({ value, changed }: { value: string | null; changed?: boolean }) {
    if (value === null || value === '') {
        return <span className="text-muted-foreground italic">Empty</span>;
    }

    return <span className={cn('break-words whitespace-pre-wrap', changed && 'font-medium')}>{value}</span>;
}

/**
 * Side-by-side of one row, member by member: what the portal holds against the other side's version. Only the
 * differences show until "Show every field" is pressed. On phones each member is a small card.
 */
export function FieldComparisonCard({
    fields,
    title,
    description,
    portalLabel,
    otherLabel,
    emptyText,
}: {
    fields: FieldComparison[];
    title: string;
    description: string;
    portalLabel: string;
    otherLabel: string;
    emptyText: string;
}) {
    const changed = fields.filter((field) => field.changed);
    const [showAll, setShowAll] = useState(changed.length === 0);
    const rows = showAll ? fields : changed;

    const toggle = fields.length > changed.length && (
        <Button type="button" variant="outline" size="sm" onClick={() => setShowAll(!showAll)} aria-pressed={showAll}>
            {showAll ? 'Show differences only' : `Show every field (${fields.length})`}
        </Button>
    );

    return (
        <SectionCard title={title} description={description} actions={toggle} flush>
            {rows.length === 0 ? (
                <p className="text-muted-foreground px-6 py-8 text-center text-sm">{emptyText}</p>
            ) : (
                <>
                    <Table className="hidden md:table">
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-1/4 pl-6">Field</TableHead>
                                <TableHead className="w-[37.5%]">{portalLabel}</TableHead>
                                <TableHead className="w-[37.5%] pr-6">{otherLabel}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((field) => (
                                <TableRow key={field.key} className={cn(field.changed && 'bg-warning-soft/40 hover:bg-warning-soft/60')}>
                                    <TableCell className="text-muted-foreground pl-6 align-top">
                                        {field.label}
                                        {field.changed && <span className="sr-only"> (different)</span>}
                                    </TableCell>
                                    <TableCell className="align-top whitespace-normal">
                                        <Value value={field.portal} />
                                    </TableCell>
                                    <TableCell className="pr-6 align-top whitespace-normal">
                                        <Value value={field.till} changed={field.changed} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <ul className="divide-y md:hidden">
                        {rows.map((field) => (
                            <li key={field.key} className={cn('grid gap-1.5 px-4 py-3 text-sm', field.changed && 'bg-warning-soft/40')}>
                                <span className="text-muted-foreground text-xs font-medium">{field.label}</span>
                                <span className="grid grid-cols-[6rem_minmax(0,1fr)] gap-2">
                                    <span className="text-muted-foreground text-xs">{portalLabel}</span>
                                    <Value value={field.portal} />
                                </span>
                                <span className="grid grid-cols-[6rem_minmax(0,1fr)] gap-2">
                                    <span className="text-muted-foreground text-xs">{otherLabel}</span>
                                    <Value value={field.till} changed={field.changed} />
                                </span>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </SectionCard>
    );
}
