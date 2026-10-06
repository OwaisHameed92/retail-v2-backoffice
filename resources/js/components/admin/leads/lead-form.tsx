import { FormCard, FormField, FormGrid, FormSection } from '@/components/shared/form-section';
import { StickyFormBar } from '@/components/shared/sticky-form-bar';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { timeZoneLabel } from '@/lib/country';
import { postcodeInputProps, postcodeLabel, townNeededWithAddress } from '@/lib/country-address';
import { Link, useForm } from '@inertiajs/react';
import { CircleAlert, LoaderCircle } from 'lucide-react';
import { type ComponentProps, type FormEventHandler, type ReactNode } from 'react';
import { type LeadFormData, type LeadOptions } from './types';

interface LeadFormProps {
    initial: LeadFormData;
    options: LeadOptions;
    /** Add form: assignment and first follow-up. */
    mode: 'create' | 'edit';
    submitUrl: string;
    cancelHref: string;
    submitLabel: string;
    /** Left side of the action bar. */
    message?: ReactNode;
}

function describedBy(id: string, error?: string) {
    return { 'aria-invalid': !!error, 'aria-describedby': error ? `${id}-error` : `${id}-help` };
}

/** The lead form: business, contact, and (on add) who looks after it. Posts to LeadRequest. */
export function LeadForm({ initial, options, mode, submitUrl, cancelHref, submitLabel, message }: LeadFormProps) {
    const { data, setData, post, put, processing, errors } = useForm<LeadFormData>(initial);
    const errorCount = Object.keys(errors).length;

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        (mode === 'create' ? post : put)(submitUrl, { preserveScroll: true });
    };

    const text = (key: keyof LeadFormData, id: string, extra: Partial<ComponentProps<typeof Input>> = {}) => (
        <Input
            id={id}
            value={String(data[key] ?? '')}
            onChange={(event) => setData(key, event.target.value as never)}
            {...describedBy(id, errors[key])}
            {...extra}
        />
    );

    return (
        <form onSubmit={submit} noValidate className="flex flex-col gap-6">
            {errorCount > 0 && (
                <Alert variant="destructive">
                    <CircleAlert className="size-4" />
                    <AlertDescription>
                        {errorCount === 1 ? 'One field needs attention.' : `${errorCount} fields need attention.`} Check the highlighted fields below.
                    </AlertDescription>
                </Alert>
            )}

            <FormCard>
                <FormSection title="Business" description="The shop or shops they want to run on SSPOS.">
                    <FormGrid>
                        <FormField id="business_name" label="Business name" error={errors.business_name} className="sm:col-span-2">
                            {text('business_name', 'business_name', { autoFocus: mode === 'create', maxLength: 160, autoComplete: 'off' })}
                        </FormField>
                        <FormField id="business_type" label="Type of business" error={errors.business_type}>
                            <Select
                                value={data.business_type}
                                onValueChange={(value) => setData('business_type', value as LeadFormData['business_type'])}
                            >
                                <SelectTrigger id="business_type" aria-invalid={!!errors.business_type}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.businessTypes.map((type) => (
                                        <SelectItem key={type.value} value={type.value}>
                                            {type.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FormField>
                        <FormField
                            id="current_system"
                            label="Current till system"
                            optional
                            help="What they use today, e.g. ICR Touch or a cash register."
                            error={errors.current_system}
                        >
                            {text('current_system', 'current_system', { maxLength: 160 })}
                        </FormField>
                        <FormField id="town" label="Town" optional={!townNeededWithAddress()} help={townNeededWithAddress() ? `Needed with a ${postcodeLabel().toLowerCase()}.` : undefined} error={errors.town}>
                            {text('town', 'town', { maxLength: 80, autoComplete: 'address-level2' })}
                        </FormField>
                        <FormField id="postcode" label={postcodeLabel()} optional error={errors.postcode}>
                            {text('postcode', 'postcode', { maxLength: 10, autoComplete: 'postal-code', className: 'uppercase', ...postcodeInputProps() })}
                        </FormField>
                    </FormGrid>
                    <FormGrid>
                        <FormField
                            id="shops_count"
                            label="Shops"
                            help={`1 to ${options.maxShops}. Each becomes a branch.`}
                            error={errors.shops_count}
                        >
                            <Input
                                id="shops_count"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={options.maxShops}
                                value={data.shops_count}
                                onChange={(event) => setData('shops_count', Number(event.target.value))}
                                className="tabular-nums"
                                {...describedBy('shops_count', errors.shops_count)}
                            />
                        </FormField>
                        <FormField id="tills_count" label="Tills in total" help="Each till gets its own licence." error={errors.tills_count}>
                            <Input
                                id="tills_count"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={options.maxTills}
                                value={data.tills_count}
                                onChange={(event) => setData('tills_count', Number(event.target.value))}
                                className="tabular-nums"
                                {...describedBy('tills_count', errors.tills_count)}
                            />
                        </FormField>
                    </FormGrid>
                </FormSection>

                <FormSection title="Contact" description="The person we speak to. They become the owner login when the trial is approved.">
                    <FormGrid>
                        <FormField id="contact_name" label="Name" error={errors.contact_name} className="sm:col-span-2">
                            {text('contact_name', 'contact_name', { maxLength: 120, autoComplete: 'off' })}
                        </FormField>
                        <FormField id="email" label="Email" help="Needed to approve a trial: the licence keys go here." error={errors.email}>
                            {text('email', 'email', { type: 'email', maxLength: 255, autoComplete: 'off' })}
                        </FormField>
                        <FormField id="phone" label="Phone" help="Email or phone is required." error={errors.phone}>
                            {text('phone', 'phone', { type: 'tel', maxLength: 20, autoComplete: 'off' })}
                        </FormField>
                    </FormGrid>
                    <label htmlFor="consent_marketing" className="flex cursor-pointer items-start gap-3">
                        <Checkbox
                            id="consent_marketing"
                            checked={data.consent_marketing}
                            onCheckedChange={(checked) => setData('consent_marketing', checked === true)}
                            className="mt-0.5"
                        />
                        <span className="grid gap-0.5">
                            <span className="text-sm font-medium">Happy to receive news and offers</span>
                            <span className="text-muted-foreground text-sm">Only tick this if they said yes.</span>
                        </span>
                    </label>
                </FormSection>

                <FormSection title="The request" description="How they reached us and what they said.">
                    <FormGrid>
                        <FormField id="source" label="Source" error={errors.source}>
                            <Select value={data.source} onValueChange={(value) => setData('source', value as LeadFormData['source'])}>
                                <SelectTrigger id="source" aria-invalid={!!errors.source}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {options.sources.map((source) => (
                                        <SelectItem key={source.value} value={source.value}>
                                            {source.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </FormField>
                    </FormGrid>
                    <FormField id="message" label="What they told us" optional error={errors.message}>
                        <Textarea
                            id="message"
                            rows={4}
                            maxLength={5000}
                            value={data.message}
                            onChange={(event) => setData('message', event.target.value)}
                            {...describedBy('message', errors.message)}
                        />
                    </FormField>
                </FormSection>

                {mode === 'create' && (
                    <FormSection title="Next step" description="Who looks after the lead and when to get back to them. You can change both later.">
                        <FormGrid>
                            <FormField id="assigned_admin_id" label="Assigned to" optional error={errors.assigned_admin_id} className="sm:col-span-2">
                                <Select
                                    value={data.assigned_admin_id || 'none'}
                                    onValueChange={(value) => setData('assigned_admin_id', value === 'none' ? '' : value)}
                                >
                                    <SelectTrigger id="assigned_admin_id" aria-invalid={!!errors.assigned_admin_id}>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">Nobody yet</SelectItem>
                                        {options.admins.map((admin) => (
                                            <SelectItem key={admin.value} value={admin.value}>
                                                {admin.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <FormField id="follow_up_date" label="Follow up on" optional error={errors.follow_up_date}>
                                {text('follow_up_date', 'follow_up_date', { type: 'date' })}
                            </FormField>
                            <FormField id="follow_up_time" label="At" optional help={`${timeZoneLabel()} time. Defaults to 09:00.`} error={errors.follow_up_time}>
                                {text('follow_up_time', 'follow_up_time', { type: 'time', disabled: !data.follow_up_date })}
                            </FormField>
                        </FormGrid>
                    </FormSection>
                )}
            </FormCard>

            <StickyFormBar message={message}>
                <Button variant="outline" asChild>
                    <Link href={cancelHref}>Cancel</Link>
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="size-4 animate-spin" />}
                    {submitLabel}
                </Button>
            </StickyFormBar>
        </form>
    );
}
