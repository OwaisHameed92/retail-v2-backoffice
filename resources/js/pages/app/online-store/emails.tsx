import EmailPreview from '@/components/admin/emails/email-preview';
import { EmailServerFields } from '@/components/app/online-store/email-server-fields';
import { EmailStatusCard } from '@/components/app/online-store/email-status-card';
import { type EmailsForm, type EmailsProps, type StoreEmailSettings } from '@/components/app/online-store/email-types';
import { OnlineStoreTabs } from '@/components/app/online-store/online-store-tabs';
import { ToggleSwitch } from '@/components/app/online-store/toggle-switch';
import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { EmptyState } from '@/components/shared/empty-state';
import { FormCard, FormField, FormGrid, FormSection } from '@/components/shared/form-section';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/app-layout';
import { Head, router, useForm } from '@inertiajs/react';
import { Info, LoaderCircle, Mail, Trash2 } from 'lucide-react';
import { useState } from 'react';

const DESCRIPTION = "The emails your customers get about their orders, in your shop's name, look and, if you like, from your own email server.";

function initial(store: StoreEmailSettings): EmailsForm {
    return {
        sender_name: store.senderName ?? '',
        reply_to: store.replyTo ?? '',
        smtp_enabled: store.smtp.enabled,
        smtp_host: store.smtp.host ?? '',
        smtp_port: store.smtp.port ? String(store.smtp.port) : '587',
        smtp_encryption: store.smtp.encryption ?? 'tls',
        smtp_username: store.smtp.username ?? '',
        smtp_password: '',
        smtp_from_address: store.smtp.fromAddress ?? '',
    };
}

function Previews({ templates }: Pick<EmailsProps, 'templates'>) {
    const [key, setKey] = useState(templates[0]?.key ?? '');
    const current = templates.find((t) => t.key === key) ?? templates[0];

    if (!current) {
        return null;
    }

    return (
        <SectionCard title="Preview" description="Your saved logo, colour and details on a sample order. Save changes to see them here.">
            <div className="grid gap-4">
                <ToggleGroup
                    type="single"
                    variant="outline"
                    size="sm"
                    value={current.key}
                    onValueChange={(v) => v && setKey(v)}
                    aria-label="Email to preview"
                    className="flex-wrap justify-start"
                >
                    {templates.map((t) => (
                        <ToggleGroupItem key={t.key} value={t.key} className="px-3">
                            {t.label.replace(/^Online store: /, '').replace(/ \(to the customer\)$/, '')}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
                <div className="grid gap-0.5">
                    <p className="text-sm">
                        <span className="text-muted-foreground">Subject: </span>
                        <span className="font-medium">{current.subject}</span>
                    </p>
                    <p className="text-muted-foreground text-xs leading-5">{current.description}</p>
                </div>
                <EmailPreview
                    templateKey={current.key}
                    label={current.label}
                    srcFor={(format) =>
                        route('app.online-store.emails.preview', { template: current.key, ...(format === 'text' ? { format: 'text' } : {}) })
                    }
                />
            </div>
        </SectionCard>
    );
}

function Emails({ store, ...props }: EmailsProps & { store: StoreEmailSettings }) {
    const { canEdit } = props;
    const form = useForm<EmailsForm>(initial(store));
    const errors = form.errors as Partial<Record<keyof EmailsForm, string>>;
    const disabled = !canEdit || form.processing;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(route('app.online-store.emails.update'), { preserveScroll: true, onSuccess: () => form.setData('smtp_password', '') });
    };
    const forget = () =>
        new Promise<void>((resolve) => {
            router.put(
                route('app.online-store.emails.update'),
                { sender_name: store.senderName ?? '', reply_to: store.replyTo ?? '', smtp_enabled: false, smtp_forget: true },
                { preserveScroll: true, onFinish: () => resolve() },
            );
        });

    return (
        <>
            <EmailStatusCard store={store} testRecipient={props.testRecipient} canEdit={canEdit} dirty={form.isDirty} />

            {!canEdit && (
                <Alert variant="info">
                    <AlertDescription>You can look at the email settings. Only users who manage every shop can change them.</AlertDescription>
                </Alert>
            )}

            <form onSubmit={submit} className="grid gap-6">
                <FormCard>
                    <FormSection title="Sender" description="The name customers see and where their replies go.">
                        <FormGrid>
                            <FormField
                                id="sender_name"
                                label="Sender name"
                                optional
                                error={errors.sender_name}
                                help={`Empty: ${store.businessName}.`}
                            >
                                <Input
                                    id="sender_name"
                                    value={form.data.sender_name}
                                    maxLength={70}
                                    placeholder={store.businessName}
                                    disabled={disabled}
                                    aria-invalid={!!errors.sender_name}
                                    aria-describedby={errors.sender_name ? 'sender_name-error' : 'sender_name-help'}
                                    onChange={(e) => form.setData('sender_name', e.target.value)}
                                />
                            </FormField>
                            <FormField
                                id="reply_to"
                                label="Reply-to address"
                                optional
                                error={errors.reply_to}
                                help={
                                    store.orderAddress
                                        ? `Empty: ${store.orderAddress} (your ${store.orderAddressSource === 'orderEmail' ? 'order' : 'business'} email).`
                                        : 'Empty: customers cannot reply. Add an address here or an order email on the Store tab.'
                                }
                            >
                                <Input
                                    id="reply_to"
                                    type="email"
                                    value={form.data.reply_to}
                                    maxLength={255}
                                    placeholder={store.orderAddress ?? 'orders@yourshop.co.uk'}
                                    disabled={disabled}
                                    aria-invalid={!!errors.reply_to}
                                    aria-describedby={errors.reply_to ? 'reply_to-error' : 'reply_to-help'}
                                    onChange={(e) => form.setData('reply_to', e.target.value)}
                                />
                            </FormField>
                        </FormGrid>
                    </FormSection>

                    <FormSection
                        title="Your own email server"
                        description="Send from your own address (Microsoft 365, Google Workspace or your web host). We use it only after a test email gets through."
                    >
                        <div className="flex items-center justify-between gap-4 rounded-lg border p-3">
                            <div>
                                <p className="text-sm font-medium">
                                    Send from our own email server
                                </p>
                                <p className="text-muted-foreground text-xs leading-5">
                                    Off: Switch &amp; Save&apos;s email service sends them, in your shop&apos;s name.
                                </p>
                            </div>
                            <ToggleSwitch
                                checked={form.data.smtp_enabled}
                                onCheckedChange={(v) => form.setData('smtp_enabled', v)}
                                label="Send from our own email server"
                                disabled={disabled}
                            />
                        </div>
                        {form.data.smtp_enabled && <EmailServerFields form={form} smtp={store.smtp} ports={props.ports} disabled={disabled} />}
                        {form.data.smtp_enabled && (
                            <Alert variant="info">
                                <Info />
                                <AlertDescription>
                                    If your server fails when an order email is sent, we send it through Switch &amp; Save&apos;s email service
                                    instead and show the problem here, so your customer still gets it.
                                </AlertDescription>
                            </Alert>
                        )}
                        {canEdit && store.smtp.saved && (
                            <div>
                                <ConfirmDialog
                                    trigger={
                                        <Button type="button" variant="outline" size="sm" disabled={form.processing}>
                                            <Trash2 />
                                            Remove server details
                                        </Button>
                                    }
                                    title="Remove your email server details?"
                                    description="The server, username and saved password are deleted. Customer emails go through Switch & Save's email service in your shop's name."
                                    confirmLabel="Remove"
                                    destructive
                                    onConfirm={forget}
                                />
                            </div>
                        )}
                    </FormSection>
                </FormCard>

                {canEdit && form.isDirty && (
                    <StickyFormBar message="You have unsaved changes.">
                        <Button type="button" variant="outline" disabled={form.processing} onClick={() => form.reset()}>
                            Discard changes
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Save changes
                        </Button>
                    </StickyFormBar>
                )}
            </form>

            <Previews templates={props.templates} />
        </>
    );
}

/**
 * OS-10: the online store's Emails tab: sender name, Reply-To, the store's own email server (password never shown),
 * a test email and previews of the customer emails in the store's look. No store yet → "not switched on".
 */
export default function OnlineStoreEmails(props: EmailsProps) {
    const s = props.store;
    // A fresh form after each save, so "unsaved changes" compares with what is saved now.
    const key = JSON.stringify(s && [s.senderName, s.replyTo, s.smtp]);

    return (
        <AppLayout>
            <Head title="Store emails" />
            <PageHeader title="Online store" description={DESCRIPTION} tabs={<OnlineStoreTabs active="emails" />} />
            {s ? (
                <Emails key={key} {...props} store={s} />
            ) : (
                <Card>
                    <EmptyState
                        icon={Mail}
                        tone="primary"
                        title="Your online store is not switched on yet"
                        body="Contact Switch & Save to switch it on. Then set up the emails your customers get here."
                    />
                </Card>
            )}
        </AppLayout>
    );
}
