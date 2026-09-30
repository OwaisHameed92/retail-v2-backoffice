import { DialogForm } from '@/components/admin/leads/dialog-form';
import { CheckField } from '@/components/app/setup/fields';
import { FormField } from '@/components/shared/form-section';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';
import { type BillingRequestKind } from './types';

const COPY: Record<BillingRequestKind, { title: string; description: string; submit: string; message: string; help: string }> = {
    cancel: {
        title: 'Ask to cancel your subscription',
        description:
            'We will call you to agree the date and settle any balance. Nothing changes until then: your tills keep working and your Direct Debit carries on until we stop it.',
        submit: 'Send cancellation request',
        message: 'Why are you cancelling?',
        help: 'For example, the shop is closing or being sold. We may be able to help.',
    },
    changeBank: {
        title: 'Change the bank account',
        description:
            'We will be in touch to move your Direct Debit to the new account, so no payment is missed. Your current account is used until then.',
        submit: 'Send request',
        message: 'Anything we should know?',
        help: 'For example, the date your old account closes.',
    },
};

/** Module 4.10: "Cancel" or "Change bank account" goes to Switch & Save as a request; the owner never cancels tills. */
export function BillingRequestDialog({ kind, open, onOpenChange }: { kind: BillingRequestKind; open: boolean; onOpenChange: (open: boolean) => void }) {
    const form = useForm<{ kind: BillingRequestKind; message: string; phone: string; confirm: boolean }>({ kind, message: '', phone: '', confirm: false });
    const { data, setData, errors, processing } = form;
    const copy = COPY[kind];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('app.billing.requests.store'), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <DialogForm
            open={open}
            onOpenChange={onOpenChange}
            title={copy.title}
            description={copy.description}
            submitLabel={copy.submit}
            processing={processing}
            onSubmit={submit}
            destructive={kind === 'cancel'}
        >
            <FormField id="request-message" label={copy.message} optional={kind !== 'cancel'} help={copy.help} error={errors.message}>
                <Textarea
                    id="request-message"
                    rows={3}
                    maxLength={1000}
                    value={data.message}
                    onChange={(e) => setData('message', e.target.value)}
                    aria-invalid={errors.message ? true : undefined}
                />
            </FormField>
            <FormField id="request-phone" label="Best number to call" optional error={errors.phone}>
                <Input id="request-phone" type="tel" maxLength={30} value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
            </FormField>
            {kind === 'cancel' && (
                <div className="grid gap-1">
                    <CheckField
                        id="request-confirm"
                        label="I want Switch & Save to cancel my subscription"
                        help="Your tills stop working on the date we agree with you."
                        checked={data.confirm}
                        onChange={(v) => setData('confirm', v)}
                    />
                    {errors.confirm && <p className="text-destructive text-sm">{errors.confirm}</p>}
                </div>
            )}
            {errors.kind && <p className="text-destructive text-sm">{errors.kind}</p>}
        </DialogForm>
    );
}
