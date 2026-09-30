import { DialogForm } from '@/components/admin/leads/dialog-form';
import { FormField } from '@/components/shared/form-section';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';
import { CheckField } from './fields';
import { type Option, type ReasonRow } from './types';

type Data = { type: string; text: string; position: string; is_active: boolean; account_code: string };

/** Add or edit a reason code in a dialog; the list reloads with a toast. */
export function ReasonDialog({
    row,
    types,
    defaultType,
    open,
    onOpenChange,
}: {
    row: ReasonRow | null;
    types: Option[];
    defaultType: string | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm<Data>({
        type: row?.type ?? defaultType ?? types[0]?.value ?? '',
        text: row?.text ?? '',
        position: row ? String(row.position) : '',
        is_active: row?.is_active ?? true,
        account_code: row?.account_code ?? '',
    });
    const { data, setData, errors, processing } = form;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onOpenChange(false) };
        if (row) {
            form.put(route('app.reasons.update', row.id), options);
        } else {
            form.post(route('app.reasons.store'), options);
        }
    };

    return (
        <DialogForm
            open={open}
            onOpenChange={onOpenChange}
            title={row ? 'Edit reason' : 'Add reason'}
            description="Staff pick a reason when they do this on the till. Every till gets it at its next sync."
            submitLabel={row ? 'Save changes' : 'Add reason'}
            processing={processing}
            onSubmit={submit}
        >
            <FormField id="reason-type" label="Asked for" error={errors.type}>
                <Select value={data.type} onValueChange={(v) => setData('type', v)}>
                    <SelectTrigger id="reason-type" aria-invalid={errors.type ? true : undefined}>
                        <SelectValue placeholder="Choose when the till asks" />
                    </SelectTrigger>
                    <SelectContent>
                        {types.map((t) => (
                            <SelectItem key={t.value} value={t.value}>
                                {t.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </FormField>
            <FormField id="reason-text" label="Reason" error={errors.text}>
                <Input
                    id="reason-text"
                    value={data.text}
                    maxLength={120}
                    placeholder="e.g. Damaged item"
                    onChange={(e) => setData('text', e.target.value)}
                    aria-invalid={errors.text ? true : undefined}
                />
            </FormField>
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <FormField id="reason-position" label="Order in the list" optional error={errors.position}>
                    <Input id="reason-position" type="number" min={0} value={data.position} onChange={(e) => setData('position', e.target.value)} />
                </FormField>
                <FormField id="reason-account" label="Account code" optional help="For your bookkeeping export." error={errors.account_code}>
                    <Input id="reason-account" value={data.account_code} maxLength={30} onChange={(e) => setData('account_code', e.target.value)} />
                </FormField>
            </div>
            <CheckField
                id="reason-active"
                label="Active"
                help="Inactive reasons are hidden on the tills but kept on past records."
                checked={data.is_active}
                onChange={(v) => setData('is_active', v)}
            />
        </DialogForm>
    );
}
