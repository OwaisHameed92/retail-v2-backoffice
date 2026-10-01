import { DescriptionList, type DescriptionItem } from '@/components/shared/description-list';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Filter } from 'lucide-react';
import { formatAuditTime, type AuditEntry } from './types';

interface AuditDrawerProps {
    entry: AuditEntry | null;
    onClose: () => void;
    tenantView: boolean;
    /** Narrow the list to this record or this person. */
    onFilter: (params: Record<string, string | undefined>) => void;
}

function Value({ value, tone }: { value: string | null; tone: 'before' | 'after' }) {
    if (value === null) {
        return <span className="text-muted-foreground italic">empty</span>;
    }

    return (
        <span className={tone === 'before' ? 'bg-danger-soft rounded px-1 py-0.5 break-all' : 'bg-success-soft rounded px-1 py-0.5 break-all'}>
            {value}
        </span>
    );
}

/** One audit entry in a side panel: who, when, where from, the record, and a field-by-field before/after diff. */
export function AuditDrawer({ entry, onClose, tenantView, onFilter }: AuditDrawerProps) {
    const actorFilter =
        entry?.actor.type === 'system'
            ? 'system'
            : entry?.actor.type === 'staff'
              ? 'staff'
              : entry?.actor.id && (entry.actor.type === 'admin' || entry.actor.type === 'user')
                ? `${entry.actor.type}:${entry.actor.id}`
                : null;

    const facts: DescriptionItem[] = entry
        ? [
              { label: 'When', value: formatAuditTime(entry.at, true) },
              { label: 'Who', value: entry.actor.detail ? `${entry.actor.name} · ${entry.actor.detail}` : entry.actor.name },
              ...(!tenantView ? [{ label: 'Business', value: entry.company?.name ?? null }] : []),
              { label: 'Record', value: entry.subject?.label ?? null },
              { label: 'Record id', value: entry.subject?.id ?? null, mono: true },
              { label: 'Action code', value: entry.action, mono: true },
              { label: 'IP address', value: entry.ip, mono: true },
              { label: 'Browser', value: entry.userAgent },
          ]
        : [];

    return (
        <Sheet open={entry !== null} onOpenChange={(open) => !open && onClose()}>
            <SheetContent className="w-full overflow-y-auto sm:max-w-xl">
                {entry && (
                    <div className="grid gap-6 p-5 sm:p-6">
                        <SheetHeader className="pr-8">
                            <SheetTitle>{entry.actionLabel}</SheetTitle>
                            <SheetDescription>
                                {entry.actor.name} · {formatAuditTime(entry.at)}
                            </SheetDescription>
                        </SheetHeader>

                        <DescriptionList items={facts} layout="rows" />

                        <section className="grid gap-3" aria-labelledby="audit-changes">
                            <h3 id="audit-changes" className="text-sm font-semibold">
                                Changes
                            </h3>
                            {entry.changes.length === 0 ? (
                                <p className="text-muted-foreground text-sm">No field values were recorded for this action.</p>
                            ) : (
                                <div className="overflow-x-auto rounded-lg border">
                                    <table className="w-full text-sm">
                                        <thead className="bg-muted/50 text-muted-foreground text-left text-xs">
                                            <tr>
                                                <th className="px-3 py-2 font-medium">Field</th>
                                                <th className="px-3 py-2 font-medium">Before</th>
                                                <th className="px-3 py-2 font-medium">After</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y">
                                            {entry.changes.map((change) => (
                                                <tr key={change.field} className="align-top">
                                                    <td className="text-muted-foreground px-3 py-2 whitespace-nowrap">{change.label}</td>
                                                    <td className="px-3 py-2 font-mono text-[13px]">
                                                        <Value value={change.before} tone="before" />
                                                    </td>
                                                    <td className="px-3 py-2 font-mono text-[13px]">
                                                        <Value value={change.after} tone="after" />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </section>

                        {entry.meta.length > 0 && (
                            <section className="grid gap-3" aria-labelledby="audit-details">
                                <h3 id="audit-details" className="text-sm font-semibold">
                                    Details
                                </h3>
                                <DescriptionList
                                    layout="rows"
                                    items={entry.meta.map((m) => ({ label: m.label, value: m.value, mono: (m.value?.length ?? 0) > 40 }))}
                                />
                            </section>
                        )}

                        <div className="flex flex-wrap gap-2 border-t pt-5">
                            {entry.subject?.id && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => onFilter({ subjectType: entry.subject?.type, subjectId: entry.subject?.id ?? undefined })}
                                >
                                    <Filter />
                                    Everything on this record
                                </Button>
                            )}
                            {actorFilter && (
                                <Button variant="outline" size="sm" onClick={() => onFilter({ actor: actorFilter })}>
                                    <Filter />
                                    Everything by {entry.actor.name}
                                </Button>
                            )}
                        </div>
                    </div>
                )}
            </SheetContent>
        </Sheet>
    );
}
