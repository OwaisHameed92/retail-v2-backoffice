import { DialogForm } from '@/components/admin/leads/dialog-form';
import { FormField } from '@/components/shared/form-section';
import { Input } from '@/components/ui/input';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface DialogProps {
    memberId: string;
    name: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

/** Digits-only PIN box: masked, no autofill, numeric keypad on phones. */
export function PinInput({ id, value, onChange, invalid }: { id: string; value: string; onChange: (value: string) => void; invalid?: boolean }) {
    return (
        <Input
            id={id}
            type="password"
            inputMode="numeric"
            autoComplete="off"
            maxLength={8}
            value={value}
            onChange={(e) => onChange(e.target.value.replace(/\D/g, ''))}
            aria-invalid={invalid ? true : undefined}
            className="font-mono tracking-[0.3em]"
        />
    );
}

/** Set or reset a till PIN (typed twice). The PIN is sent once and never shown again. */
export function PinDialog({ memberId, name, open, onOpenChange }: DialogProps) {
    const form = useForm({ pin: '', pin_confirmation: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.put(route('app.staff.pin', memberId), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onFinish: () => form.reset(),
        });
    };

    return (
        <DialogForm
            open={open}
            onOpenChange={onOpenChange}
            title={`New PIN for ${name}`}
            description="4 to 8 digits that no colleague uses. It replaces their current PIN on every till at the next sync. Tell them in person."
            submitLabel="Set PIN"
            processing={form.processing}
            onSubmit={submit}
            className="sm:max-w-md"
        >
            <FormField id="new-pin" label="New PIN" error={form.errors.pin}>
                <PinInput id="new-pin" value={form.data.pin} onChange={(v) => form.setData('pin', v)} invalid={Boolean(form.errors.pin)} />
            </FormField>
            <FormField id="new-pin-confirmation" label="Type it again">
                <PinInput id="new-pin-confirmation" value={form.data.pin_confirmation} onChange={(v) => form.setData('pin_confirmation', v)} />
            </FormField>
        </DialogForm>
    );
}

/**
 * Give a fob or swap it for a new one. Removing a fob happens at the till (a blank fob in the sync keeps the till's,
 * contract answers 2026-09-29-b A.1), so there is no "remove" here.
 */
export function FobDialog({ memberId, name, open, onOpenChange, hasFob }: DialogProps & { hasFob: boolean }) {
    const form = useForm({ rfid: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.put(route('app.staff.fob', memberId), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onFinish: () => form.reset(),
        });
    };

    return (
        <DialogForm
            open={open}
            onOpenChange={onOpenChange}
            title={hasFob ? `New fob for ${name}` : `Give ${name} a fob`}
            description={
                hasFob
                    ? 'The new fob replaces their old one on every till at the next sync; the old fob stops working.'
                    : 'They can tap it on the till instead of typing their PIN, after the next sync.'
            }
            submitLabel="Save fob"
            processing={form.processing}
            onSubmit={submit}
            className="sm:max-w-md"
        >
            <FormField
                id="fob-code"
                label="Fob code"
                help="Click here and tap the fob on a USB reader, or type the code printed on it."
                error={form.errors.rfid}
            >
                <Input
                    id="fob-code"
                    type="password"
                    autoComplete="off"
                    maxLength={64}
                    value={form.data.rfid}
                    onChange={(e) => form.setData('rfid', e.target.value)}
                    aria-invalid={form.errors.rfid ? true : undefined}
                    className="font-mono"
                />
            </FormField>
        </DialogForm>
    );
}
