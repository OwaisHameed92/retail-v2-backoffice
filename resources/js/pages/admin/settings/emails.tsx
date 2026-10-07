import { HeldEmailHistory, HeldEmailList } from '@/components/admin/emails/held-email-list';
import { type EmailCategorySetting, type HeldEmailRow } from '@/components/admin/emails/types';
import { PageHeader } from '@/components/shared/page-header';
import { SectionCard } from '@/components/shared/section-card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/admin-layout';
import { Head, useForm } from '@inertiajs/react';
import { Info, LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface EmailSettingsProps {
    categories: EmailCategorySetting[];
    held: HeldEmailRow[];
    heldTotal: number;
    recent: HeldEmailRow[];
}

/**
 * Admin → Settings → Emails (P11): which tenant emails go out by themselves. An email whose kind is off is held
 * until an admin checks it and sends it (here, or on the business's Emails tab). Billing itself is not affected.
 */
export default function EmailSettings({ categories, held, heldTotal, recent }: EmailSettingsProps) {
    const initial = Object.fromEntries(categories.map((category) => [category.value, category.sendAutomatically]));
    const { data, setData, put, processing, isDirty, setDefaults } = useForm<{ settings: Record<string, boolean> }>({ settings: initial });
    const off = categories.filter((category) => !data.settings[category.value]).length;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        put(route('admin.settings.emails.update'), { preserveScroll: true, onSuccess: () => setDefaults() });
    };

    const setAll = (on: boolean) => setData('settings', Object.fromEntries(categories.map((category) => [category.value, on])));

    return (
        <AdminLayout>
            <Head title="Email settings" />
            <PageHeader
                title="Email settings"
                description="Choose which emails to businesses go out by themselves. The others wait here until you check and send them."
            />

            <form onSubmit={submit} className="contents" noValidate>
                <SectionCard
                    title="Send automatically"
                    description="Off: the email is held, not sent. Invoices, reminders, suspensions and Direct Debit carry on exactly the same; only the emails wait."
                    actions={
                        <>
                            <Button type="button" size="sm" variant="outline" onClick={() => setAll(false)} disabled={processing}>
                                Hold all
                            </Button>
                            <Button type="button" size="sm" variant="outline" onClick={() => setAll(true)} disabled={processing}>
                                Send all automatically
                            </Button>
                        </>
                    }
                    footer={
                        <div className="flex w-full flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <span className="text-muted-foreground">
                                {off === 0 ? 'Every email goes automatically.' : `${off} of ${categories.length} kinds held for you to send.`}
                            </span>
                            <Button type="submit" disabled={processing || !isDirty}>
                                {processing && <LoaderCircle className="size-4 animate-spin" />}
                                Save changes
                            </Button>
                        </div>
                    }
                >
                    <ul className="divide-border grid divide-y">
                        {categories.map((category) => (
                            <li key={category.value} className="flex items-start gap-3 py-4 first:pt-0 last:pb-0">
                                <Checkbox
                                    id={`auto-${category.value}`}
                                    checked={data.settings[category.value]}
                                    onCheckedChange={(checked) => setData('settings', { ...data.settings, [category.value]: checked === true })}
                                    className="mt-0.5"
                                />
                                <div className="grid min-w-0 flex-1 gap-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Label htmlFor={`auto-${category.value}`} className="font-medium">
                                            {category.label}
                                        </Label>
                                        <Badge variant={data.settings[category.value] ? 'success' : 'warning'}>
                                            {data.settings[category.value] ? 'Sent automatically' : 'Held for you'}
                                        </Badge>
                                        {category.held > 0 && <Badge variant="neutral">{category.held} waiting</Badge>}
                                    </div>
                                    <p className="text-muted-foreground text-sm">{category.description}</p>
                                </div>
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            </form>

            <Alert variant="info">
                <Info className="size-4" />
                <AlertDescription>
                    Always sent, whatever is chosen here: a password reset or sign-in code a person asks for, invitations and customer statements a
                    business sends, licence keys you email from a licence, and our own staff emails.
                </AlertDescription>
            </Alert>

            <SectionCard
                title={`Held emails${heldTotal > 0 ? ` (${heldTotal})` : ''}`}
                description="Check the values each email will send, preview it, then send or discard it. A business’s own list (with Send all) is on its Emails tab."
            >
                <HeldEmailList emails={held} showCompany reload={['categories', 'held', 'heldTotal', 'recent']} />
                {heldTotal > held.length && (
                    <p className="text-muted-foreground mt-3 text-sm">
                        Showing the newest {held.length} of {heldTotal}. Open a business to see all of its emails.
                    </p>
                )}
            </SectionCard>

            <SectionCard title="Recently sent or discarded">
                <HeldEmailHistory emails={recent} showCompany />
            </SectionCard>
        </AdminLayout>
    );
}
