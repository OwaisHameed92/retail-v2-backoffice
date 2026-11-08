import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import { type ComponentProps, type ReactNode } from 'react';

/** A labelled input with its helper and error text (label above, error under, as BRAND.md forms). */
export function SalonField({
    id,
    label,
    error,
    hint,
    className,
    children,
    ...input
}: { id: string; label: string; error?: string; hint?: ReactNode; children?: ReactNode } & ComponentProps<typeof Input>) {
    return (
        <div className={cn('space-y-1.5', className)}>
            <Label htmlFor={id}>{label}</Label>
            {children ?? <Input id={id} aria-invalid={error ? true : undefined} aria-describedby={error ? `${id}-error` : undefined} {...input} />}
            {hint && !error && <p className="text-muted-foreground text-xs">{hint}</p>}
            {error && (
                <p id={`${id}-error`} className="text-destructive text-xs">
                    {error}
                </p>
            )}
        </div>
    );
}

/** A tick box with its label beside it. */
export function SalonTick({
    id,
    checked,
    onChange,
    children,
}: {
    id: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    children: ReactNode;
}) {
    return (
        <div className="flex items-start gap-3">
            <Checkbox id={id} checked={checked} onCheckedChange={(state) => onChange(state === true)} className="mt-0.5" />
            <Label htmlFor={id} className="leading-snug font-normal">
                {children}
            </Label>
        </div>
    );
}

export interface MarketingChoice {
    sms: boolean;
    email: boolean;
    whatsApp: boolean;
}

/**
 * The three marketing boxes (README §11): one per channel, never ticked for the client, apart from the terms. Service
 * messages (confirmation, reminders) need none of them.
 */
export function MarketingTicks({
    value,
    onChange,
    shopName,
}: {
    value: MarketingChoice;
    onChange: (value: MarketingChoice) => void;
    shopName: string;
}) {
    return (
        <fieldset className="space-y-3">
            <legend className="font-medium">Offers and news from {shopName}</legend>
            <p className="text-muted-foreground text-xs">Optional. Tick how you would like to hear from us. Booking reminders are sent either way.</p>
            <SalonTick id="marketing-sms" checked={value.sms} onChange={(sms) => onChange({ ...value, sms })}>
                By text message
            </SalonTick>
            <SalonTick id="marketing-email" checked={value.email} onChange={(email) => onChange({ ...value, email })}>
                By e-mail
            </SalonTick>
            <SalonTick id="marketing-whatsapp" checked={value.whatsApp} onChange={(whatsApp) => onChange({ ...value, whatsApp })}>
                By WhatsApp
            </SalonTick>
        </fieldset>
    );
}
