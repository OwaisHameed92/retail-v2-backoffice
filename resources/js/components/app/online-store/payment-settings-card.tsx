import { ConfirmDialog } from '@/components/shared/confirm-dialog';
import { FormField } from '@/components/shared/form-section';
import { SectionCard } from '@/components/shared/section-card';
import { StatusPill } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router, useForm } from '@inertiajs/react';
import { LoaderCircle, ShieldCheck } from 'lucide-react';
import { ToggleSwitch } from './toggle-switch';
import { type DeliveryPaymentsProps, type PaymentValue } from './types';

type Payments = NonNullable<DeliveryPaymentsProps['payments']>;
type Dna = NonNullable<DeliveryPaymentsProps['dna']>;

/** OS-9: the ways to pay per method. Card online needs the DNA Payments details saved first. */
export function WaysToPayCard({ payments, disabled }: { payments: Payments; disabled: boolean }) {
    const form = useForm({ collection: payments.collection.chosen, delivery: payments.delivery.chosen });
    const errors = form.errors as Record<string, string | undefined>;
    const toggle = (method: 'collection' | 'delivery', value: PaymentValue, on: boolean) =>
        form.setData(method, on ? [...form.data[method], value] : form.data[method].filter((v) => v !== value));
    const off = disabled || form.processing;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.put(route('app.online-store.delivery.payments'), { preserveScroll: true, onSuccess: () => form.setDefaults() });
            }}
        >
            <SectionCard
                title="Ways to pay"
                description="Choose how customers pay for each way of getting their order. Each needs at least one."
                footer={
                    <div className="flex w-full justify-end">
                        <Button type="submit" disabled={off || !form.isDirty}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Save ways to pay
                        </Button>
                    </div>
                }
            >
                <div className="grid gap-6 sm:grid-cols-2">
                    {(['collection', 'delivery'] as const).map((method) => (
                        <fieldset key={method} className="grid content-start gap-3">
                            <legend className="mb-1 text-sm font-semibold">{method === 'collection' ? 'Click & collect' : 'Delivery'}</legend>
                            {payments[method].options.map((option) => {
                                const id = `pay-${method}-${option.value}`;
                                const locked = option.value === 'cardOnline' && !payments.cardReady;

                                return (
                                    <div key={option.value} className="flex items-start gap-3">
                                        <Checkbox
                                            id={id}
                                            checked={form.data[method].includes(option.value)}
                                            disabled={off || locked}
                                            onCheckedChange={(on) => toggle(method, option.value, on === true)}
                                            className="mt-0.5"
                                        />
                                        <div className="grid gap-0.5">
                                            <Label htmlFor={id}>{option.label}</Label>
                                            {locked && <p className="text-muted-foreground text-xs">Save your DNA Payments details below first.</p>}
                                        </div>
                                    </div>
                                );
                            })}
                            {errors[method] && <p className="text-danger-foreground text-[13px]">{errors[method]}</p>}
                        </fieldset>
                    ))}
                </div>
            </SectionCard>
        </form>
    );
}

/**
 * OS-9: the store's own DNA Payments account. The saved details are never sent back: only their last 4 characters.
 * Leave the secret blank to keep the saved one.
 */
export function DnaCard({ dna, disabled }: { dna: Dna; disabled: boolean }) {
    const form = useForm({ client_id: '', client_secret: '', terminal_id: '', test_mode: dna.testMode as boolean });
    const errors = form.errors as Record<string, string | undefined>;
    const off = disabled || form.processing;

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.put(route('app.online-store.delivery.dna'), {
                    preserveScroll: true,
                    onSuccess: () => form.reset('client_id', 'client_secret', 'terminal_id'),
                });
            }}
        >
            <SectionCard
                title={
                    <span className="flex flex-wrap items-center gap-2">
                        Card payments online (DNA Payments)
                        {dna.configured ? (
                            <StatusPill tone={dna.testMode ? 'warning' : 'success'}>{dna.testMode ? 'Test mode' : 'Live'}</StatusPill>
                        ) : (
                            <StatusPill tone="neutral">Not set up</StatusPill>
                        )}
                    </span>
                }
                description="Customers pay on DNA's secure page and the money goes straight to your DNA account. Find these details in your DNA Payments merchant portal."
                actions={
                    dna.configured && (
                        <ConfirmDialog
                            trigger={
                                <Button type="button" variant="outline" size="sm" disabled={off}>
                                    Remove
                                </Button>
                            }
                            title="Remove the DNA Payments details?"
                            description="Customers can no longer pay by card online. Collections fall back to pay at the shop, deliveries to pay on delivery."
                            confirmLabel="Remove"
                            onConfirm={() =>
                                new Promise<void>((resolve) =>
                                    router.delete(route('app.online-store.delivery.dna.remove'), { preserveScroll: true, onFinish: () => resolve() }),
                                )
                            }
                        />
                    )
                }
                footer={
                    <div className="flex w-full flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-muted-foreground flex items-center gap-2 text-sm">
                            <ShieldCheck className="size-4 shrink-0" aria-hidden />
                            Stored encrypted. Refunds are made in your DNA portal.
                        </p>
                        <Button type="submit" disabled={off || (!form.isDirty && dna.configured)}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            {dna.configured ? 'Save changes' : 'Save details'}
                        </Button>
                    </div>
                }
            >
                <div className="grid gap-4 sm:grid-cols-3">
                    <FormField
                        id="dna-client"
                        label="Client ID"
                        error={errors.client_id}
                        help={dna.clientId ? `Saved: ${dna.clientId}. Enter it again to change any detail.` : undefined}
                    >
                        <Input
                            id="dna-client"
                            autoComplete="off"
                            value={form.data.client_id}
                            disabled={off}
                            aria-invalid={!!errors.client_id}
                            onChange={(e) => form.setData('client_id', e.target.value)}
                        />
                    </FormField>
                    <FormField
                        id="dna-secret"
                        label="Client secret"
                        error={errors.client_secret}
                        help={dna.secret ? `Saved: ${dna.secret}. Leave blank to keep it.` : undefined}
                    >
                        <Input
                            id="dna-secret"
                            type="password"
                            autoComplete="new-password"
                            value={form.data.client_secret}
                            disabled={off}
                            aria-invalid={!!errors.client_secret}
                            onChange={(e) => form.setData('client_secret', e.target.value)}
                        />
                    </FormField>
                    <FormField
                        id="dna-terminal"
                        label="Terminal ID"
                        error={errors.terminal_id}
                        help={dna.terminalId ? `Saved: ${dna.terminalId}.` : undefined}
                    >
                        <Input
                            id="dna-terminal"
                            autoComplete="off"
                            value={form.data.terminal_id}
                            disabled={off}
                            aria-invalid={!!errors.terminal_id}
                            onChange={(e) => form.setData('terminal_id', e.target.value)}
                        />
                    </FormField>
                </div>
                <div className="mt-4 flex items-center gap-3">
                    <ToggleSwitch
                        id="dna-test"
                        checked={form.data.test_mode}
                        disabled={off}
                        onCheckedChange={(on) => form.setData('test_mode', on)}
                    />
                    <Label htmlFor="dna-test" className="font-normal">
                        Test mode: DNA's test environment, no real money (until DNA approves your account)
                    </Label>
                </div>
            </SectionCard>
        </form>
    );
}
