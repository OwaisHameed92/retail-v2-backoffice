import { EmailPreview } from '@/components/admin/emails/email-preview';
import { EmailTabs } from '@/components/admin/emails/email-tabs';
import { type EmailTemplateItem, type EmailToast as EmailToastData } from '@/components/admin/emails/types';
import { type AdminSharedData } from '@/components/admin/types';
import { PageHeader } from '@/components/shared/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AdminLayout from '@/layouts/admin-layout';
import { cn } from '@/lib/utils';
import { Head, router, usePage } from '@inertiajs/react';
import { LoaderCircle, Send } from 'lucide-react';
import { useState } from 'react';

interface EmailTemplatesProps {
    templates: EmailTemplateItem[];
    selected: string;
    toast?: EmailToastData | null;
}

function AudienceBadge({ audience }: { audience: EmailTemplateItem['audience'] }) {
    return (
        <Badge variant="outline" className="font-normal">
            {audience === 'staff' ? 'Staff' : 'Customer'}
        </Badge>
    );
}

export default function EmailTemplates({ templates, selected }: EmailTemplatesProps) {
    const { admin } = usePage<AdminSharedData>().props;
    const [sending, setSending] = useState(false);
    const current = templates.find((template) => template.key === selected) ?? templates[0];

    const select = (key: string) =>
        router.get(route('admin.emails.templates'), { template: key }, { preserveState: true, preserveScroll: true, replace: true });

    const sendTest = () =>
        router.post(
            route('admin.emails.templates.test', { template: current.key }),
            {},
            { preserveScroll: true, onStart: () => setSending(true), onFinish: () => setSending(false) },
        );

    return (
        <AdminLayout>
            <Head title="Email templates" />
            <PageHeader title="Emails" description="Every email we send, shown with sample data. Nothing here is real customer data." />
            <EmailTabs />

            <div className="grid gap-6 lg:grid-cols-[18rem_1fr]">
                {/* Phones: a select. Desktop: a list. */}
                <div className="lg:hidden">
                    <Select value={current.key} onValueChange={select}>
                        <SelectTrigger aria-label="Template">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {templates.map((template) => (
                                <SelectItem key={template.key} value={template.key}>
                                    {template.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <nav aria-label="Templates" className="hidden lg:block">
                    <ul className="flex flex-col gap-1">
                        {templates.map((template) => {
                            const active = template.key === current.key;

                            return (
                                <li key={template.key}>
                                    <button
                                        type="button"
                                        onClick={() => select(template.key)}
                                        aria-current={active ? 'true' : undefined}
                                        className={cn(
                                            'focus-visible:ring-ring w-full rounded-lg px-3 py-2.5 text-left transition-colors focus-visible:ring-2 focus-visible:outline-none',
                                            active ? 'bg-card ring-border shadow-sm ring-1' : 'hover:bg-muted/60',
                                        )}
                                    >
                                        <span className={cn('block text-sm font-medium', active && 'text-primary')}>{template.label}</span>
                                        <span className="text-muted-foreground mt-0.5 line-clamp-1 block text-xs">{template.subject}</span>
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </nav>

                <Card className="flex min-w-0 flex-col gap-5 p-4 sm:p-6">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="text-base font-semibold">{current.label}</h2>
                                <AudienceBadge audience={current.audience} />
                            </div>
                            <p className="text-muted-foreground mt-1 text-sm">{current.description}</p>
                        </div>
                        <Button onClick={sendTest} disabled={sending} className="shrink-0">
                            {sending ? <LoaderCircle className="animate-spin" /> : <Send />}
                            Send test to me
                        </Button>
                    </div>

                    <dl className="bg-muted/30 grid gap-2 rounded-lg border p-3 text-sm sm:grid-cols-[5rem_1fr]">
                        <dt className="text-muted-foreground">Subject</dt>
                        <dd className="min-w-0 font-medium break-words">{current.subject}</dd>
                        <dt className="text-muted-foreground">Test to</dt>
                        <dd className="min-w-0 break-words">{admin.email}</dd>
                    </dl>

                    <EmailPreview key={current.key} templateKey={current.key} label={current.label} />
                </Card>
            </div>
        </AdminLayout>
    );
}
