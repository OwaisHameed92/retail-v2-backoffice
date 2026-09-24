import { formatDateTime } from '@/components/admin/format';
import { StatusBadge } from '@/components/shared/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Link } from '@inertiajs/react';
import { CircleAlert, Eye } from 'lucide-react';
import { type ReactNode } from 'react';
import { emailStatusTones, type EmailLogRow } from './types';

interface EmailLogSheetProps {
    log: EmailLogRow | null;
    templateKeys: string[];
    onOpenChange: (open: boolean) => void;
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid grid-cols-[8rem_1fr] gap-3 py-2.5 text-sm">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="min-w-0 break-words">{children}</dd>
        </div>
    );
}

/** "business_name" → "Business name" */
function metaLabel(key: string): string {
    const words = key
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .replace(/[_-]+/g, ' ')
        .toLowerCase();

    return words.charAt(0).toUpperCase() + words.slice(1);
}

function metaValue(value: string | number | boolean | null): string {
    if (value === null) {
        return '—';
    }
    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    return String(value);
}

/** Right-hand drawer with everything we logged about one email. Bodies and secrets are never stored. */
export function EmailLogSheet({ log, templateKeys, onOpenChange }: EmailLogSheetProps) {
    const metaEntries = log ? Object.entries(log.meta).filter(([key]) => key !== 'test') : [];

    return (
        <Sheet open={log !== null} onOpenChange={onOpenChange}>
            <SheetContent className="flex w-full flex-col gap-0 overflow-y-auto p-0 sm:max-w-lg">
                {log && (
                    <>
                        <SheetHeader className="gap-2 border-b p-6 pr-12">
                            <div className="flex flex-wrap items-center gap-2">
                                <StatusBadge status={log.status} tones={emailStatusTones} />
                                {log.isTest && (
                                    <Badge variant="outline" className="font-normal">
                                        Test
                                    </Badge>
                                )}
                            </div>
                            <SheetTitle className="text-base leading-snug">{log.subject ?? 'No subject'}</SheetTitle>
                            <SheetDescription>To {log.to}</SheetDescription>
                        </SheetHeader>

                        <div className="flex flex-col gap-6 p-6">
                            {log.status === 'failed' && log.error && (
                                <div className="border-destructive/30 bg-danger-soft flex gap-3 rounded-lg border p-3 text-sm">
                                    <CircleAlert className="text-destructive mt-0.5 size-4 shrink-0" aria-hidden />
                                    <div className="min-w-0">
                                        <p className="text-destructive font-medium">Sending failed</p>
                                        <p className="text-muted-foreground mt-1 break-words">{log.error}</p>
                                    </div>
                                </div>
                            )}

                            <section>
                                <h3 className="text-sm font-semibold">Details</h3>
                                <dl className="mt-1 divide-y">
                                    <Fact label="Template">{log.templateLabel}</Fact>
                                    <Fact label="Business">{log.company?.name ?? <span className="text-muted-foreground">None</span>}</Fact>
                                    <Fact label="Queued">{formatDateTime(log.createdAt)}</Fact>
                                    <Fact label="Sent">{formatDateTime(log.sentAt, 'Not sent')}</Fact>
                                    <Fact label="Message ID">
                                        {log.messageId ? (
                                            <span className="font-mono text-xs">{log.messageId}</span>
                                        ) : (
                                            <span className="text-muted-foreground">None</span>
                                        )}
                                    </Fact>
                                    <Fact label="Mailable">
                                        <span className="font-mono text-xs">{log.mailable}</span>
                                    </Fact>
                                </dl>
                            </section>

                            {metaEntries.length > 0 && (
                                <section>
                                    <h3 className="text-sm font-semibold">Facts in this email</h3>
                                    <dl className="mt-1 divide-y">
                                        {metaEntries.map(([key, value]) => (
                                            <Fact key={key} label={metaLabel(key)}>
                                                {metaValue(value)}
                                            </Fact>
                                        ))}
                                    </dl>
                                </section>
                            )}

                            <p className="text-muted-foreground text-sm">
                                We do not keep email bodies, licence keys or password links. Preview the template to see what this email looks like.
                            </p>

                            {templateKeys.includes(log.template) && (
                                <Button variant="outline" asChild className="self-start">
                                    <Link href={route('admin.emails.templates', { template: log.template })}>
                                        <Eye />
                                        Preview template
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

export default EmailLogSheet;
