import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { FormField } from '@/components/shared/form-section';
import { SectionCard } from '@/components/shared/section-card';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Link, router, useForm } from '@inertiajs/react';
import { LoaderCircle, UserRoundX } from 'lucide-react';
import { type FormEvent } from 'react';
import { day } from './request-parts';
import { type DueCustomer, type RetentionSettings } from './types';

/** Data retention: how long customer details are kept after their last activity, and who is past it now. */
export function RetentionCard({ settings, due }: { settings: RetentionSettings; due: DueCustomer[] }) {
    const { data, setData, put, processing, errors, isDirty } = useForm({
        retention_months: settings.retentionMonths === null ? '' : String(settings.retentionMonths),
        auto_anonymise: settings.autoAnonymise,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        put(route('app.privacy.settings.update'), { preserveScroll: true });
    };

    return (
        <SectionCard
            title="Data retention"
            description="How long to keep a customer's details after their last purchase, account entry or change. Past that, they can be anonymised; their sales and account history stay for your accounts."
        >
            <form onSubmit={submit} className="grid gap-5">
                <div className="grid gap-4 sm:grid-cols-[minmax(0,16rem)_1fr] sm:items-start">
                    <FormField
                        id="retention_months"
                        label="Keep customer details for (months)"
                        optional
                        help={`Blank = keep until the customer asks. ${settings.minMonths}–${settings.maxMonths} months; 72 (6 years) is common.`}
                        error={errors.retention_months}
                    >
                        <Input
                            id="retention_months"
                            type="number"
                            inputMode="numeric"
                            min={settings.minMonths}
                            max={settings.maxMonths}
                            value={data.retention_months}
                            aria-invalid={!!errors.retention_months}
                            onChange={(e) => setData('retention_months', e.target.value)}
                        />
                    </FormField>
                    <div className="flex items-start gap-3 pt-7">
                        <Checkbox
                            id="auto_anonymise"
                            checked={data.auto_anonymise}
                            disabled={data.retention_months === ''}
                            onCheckedChange={(v) => setData('auto_anonymise', v === true)}
                        />
                        <div className="grid gap-1 leading-5">
                            <Label htmlFor="auto_anonymise">Anonymise automatically every night</Label>
                            <p className="text-muted-foreground text-sm">
                                Off: we only count who is past the period, and you anonymise them here. Customers whose account is not settled are
                                always skipped.
                            </p>
                        </div>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-3">
                    <Button type="submit" disabled={processing || !isDirty}>
                        {processing && <LoaderCircle className="animate-spin" />}
                        Save retention
                    </Button>
                    <p className="text-muted-foreground text-sm">The period is also sent to your tills.</p>
                </div>
            </form>

            {settings.retentionMonths !== null && (
                <div className="border-border mt-6 grid gap-3 border-t pt-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="grid gap-0.5">
                            <p className="font-medium">
                                {settings.dueCount === 0
                                    ? 'No customers are past the retention period'
                                    : `${settings.dueCount} ${settings.dueCount === 1 ? 'customer is' : 'customers are'} past the retention period`}
                            </p>
                            <p className="text-muted-foreground text-sm">No activity since {day(settings.cutoff)}.</p>
                        </div>
                        {settings.dueCount > 0 && (
                            <ConfirmDialog
                                trigger={
                                    <Button variant="destructive">
                                        <UserRoundX />
                                        Anonymise them now
                                    </Button>
                                }
                                title={`Anonymise ${settings.dueCount === 1 ? '1 customer' : `${settings.dueCount} customers`}?`}
                                description="Their personal details are removed here and on every till. Customers whose account is not settled are skipped. This cannot be undone."
                                confirmLabel="Anonymise"
                                destructive
                                onConfirm={() =>
                                    new Promise((resolve) =>
                                        router.post(
                                            route('app.privacy.retention.apply'),
                                            {},
                                            { preserveScroll: true, onFinish: () => resolve(null) },
                                        ),
                                    )
                                }
                            />
                        )}
                    </div>
                    {due.length > 0 && (
                        <ul className="divide-border border-border divide-y rounded-lg border">
                            {due.map((c) => (
                                <li key={c.id} className="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                                    <Link href={route('app.customers.show', c.id)} className="font-medium hover:underline">
                                        {c.name || 'Unnamed customer'}
                                    </Link>
                                    <span className="text-muted-foreground text-xs">
                                        Last activity {day(c.lastActivity)}
                                        {!c.settled && ' · account not settled'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </SectionCard>
    );
}
