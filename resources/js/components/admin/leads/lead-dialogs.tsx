import { FormField } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { useEffect, type FormEventHandler } from 'react';
import { DialogForm } from './dialog-form';
import { FollowUpFields, dateInputValue, timeInputValue } from './follow-up-fields';
import { formatDateTimeShort } from './format';
import { type LeadDetail, type LeadOptions } from './types';

interface LeadDialogProps {
    lead: LeadDetail;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

/** Resets a form each time its dialog opens. */
function useResetOnOpen(open: boolean, reset: () => void, clearErrors: () => void) {
    useEffect(() => {
        if (open) {
            reset();
            clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);
}

export function ContactedDialog({ lead, open, onOpenChange, defaultTime }: LeadDialogProps & { defaultTime: string }) {
    const form = useForm({ note: '', next_date: '', next_time: defaultTime });
    useResetOnOpen(open, form.reset, form.clearErrors);
    const due = lead.followUpAt !== null && new Date(lead.followUpAt).getTime() <= Date.now();

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('admin.leads.contacted', lead.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <DialogForm
            open={open}
            onOpenChange={onOpenChange}
            title={lead.status === 'new' ? `Mark ${lead.businessName} as contacted` : `Log another call with ${lead.businessName}`}
            description={
                due
                    ? `The follow-up due ${formatDateTimeShort(lead.followUpAt)} is cleared, unless you set the next one.`
                    : 'Add what you talked about and, if you agreed one, when to get back to them.'
            }
            submitLabel="Mark contacted"
            processing={form.processing}
            onSubmit={submit}
        >
            <FormField id="contacted-note" label="What happened" optional error={form.errors.note}>
                <Textarea
                    id="contacted-note"
                    rows={3}
                    autoFocus
                    maxLength={2000}
                    placeholder="Spoke to the owner, wants to start after the bank holiday."
                    value={form.data.note}
                    onChange={(event) => form.setData('note', event.target.value)}
                />
            </FormField>
            <FollowUpFields
                idPrefix="contacted-next"
                dateLabel="Next follow-up"
                optional
                date={form.data.next_date}
                time={form.data.next_time}
                onDate={(value) => form.setData('next_date', value)}
                onTime={(value) => form.setData('next_time', value)}
                dateError={form.errors.next_date}
                timeError={form.errors.next_time}
            />
        </DialogForm>
    );
}

export function FollowUpDialog({ lead, open, onOpenChange, defaultTime }: LeadDialogProps & { defaultTime: string }) {
    const form = useForm({
        date: dateInputValue(lead.followUpAt),
        time: timeInputValue(lead.followUpAt, defaultTime),
        note: '',
    });
    useEffect(() => {
        if (open) {
            form.setDefaults({ date: dateInputValue(lead.followUpAt), time: timeInputValue(lead.followUpAt, defaultTime), note: '' });
            form.setData({ date: dateInputValue(lead.followUpAt), time: timeInputValue(lead.followUpAt, defaultTime), note: '' });
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('admin.leads.follow-up', lead.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    const clear = () => {
        form.transform(() => ({ date: '', time: '', note: '' }));
        form.post(route('admin.leads.follow-up', lead.id), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onFinish: () => form.transform((data) => data),
        });
    };

    return (
        <DialogForm
            open={open}
            onOpenChange={onOpenChange}
            title={lead.followUpAt ? 'Change follow-up' : 'Set a follow-up'}
            description={`When should someone get back to ${lead.contactName}? It shows on the lead list and turns red when it is overdue.`}
            submitLabel="Save follow-up"
            processing={form.processing}
            onSubmit={submit}
            disabled={!form.data.date}
            extraActions={
                lead.followUpAt ? (
                    <Button type="button" variant="ghost" className="text-muted-foreground" onClick={clear} disabled={form.processing}>
                        Clear follow-up
                    </Button>
                ) : undefined
            }
        >
            <FollowUpFields
                idPrefix="follow-up"
                date={form.data.date}
                time={form.data.time}
                onDate={(value) => form.setData('date', value)}
                onTime={(value) => form.setData('time', value)}
                dateError={form.errors.date}
                timeError={form.errors.time}
            />
            <FormField id="follow-up-note" label="Reminder" optional help="Shown on the timeline." error={form.errors.note}>
                <Textarea
                    id="follow-up-note"
                    rows={2}
                    maxLength={500}
                    placeholder="Call after 4pm, they are at the cash and carry in the morning."
                    value={form.data.note}
                    onChange={(event) => form.setData('note', event.target.value)}
                />
            </FormField>
        </DialogForm>
    );
}

export function AssignDialog({ lead, open, onOpenChange, options }: LeadDialogProps & { options: LeadOptions }) {
    const form = useForm({ admin_id: lead.assignedAdmin?.id ?? '' });
    useEffect(() => {
        if (open) {
            form.setData('admin_id', lead.assignedAdmin?.id ?? '');
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('admin.leads.assign', lead.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <DialogForm
            open={open}
            onOpenChange={onOpenChange}
            title="Assign lead"
            description="The person who looks after this lead. They see it under “My leads”."
            submitLabel="Save"
            processing={form.processing}
            onSubmit={submit}
            disabled={form.data.admin_id === (lead.assignedAdmin?.id ?? '')}
        >
            <FormField id="assign-admin" label="Assigned to" error={form.errors.admin_id} help="Owner and sales staff can work leads.">
                <Select value={form.data.admin_id || 'none'} onValueChange={(value) => form.setData('admin_id', value === 'none' ? '' : value)}>
                    <SelectTrigger id="assign-admin" aria-invalid={!!form.errors.admin_id}>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="none">Nobody (unassigned)</SelectItem>
                        {options.admins.map((admin) => (
                            <SelectItem key={admin.value} value={admin.value}>
                                {admin.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </FormField>
        </DialogForm>
    );
}

export function RejectDialog({ lead, open, onOpenChange }: LeadDialogProps) {
    const form = useForm<{ reason: string; notify: boolean }>({ reason: '', notify: false });
    useResetOnOpen(open, form.reset, form.clearErrors);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(route('admin.leads.reject', lead.id), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <DialogForm
            open={open}
            onOpenChange={onOpenChange}
            title={`Reject ${lead.businessName}?`}
            description="The lead moves to Rejected and its follow-up is cleared. You can reopen it later."
            submitLabel="Reject lead"
            destructive
            processing={form.processing}
            onSubmit={submit}
            disabled={form.data.reason.trim().length < 3}
        >
            <FormField id="reject-reason" label="Reason" help="Only staff see this. It is never sent to the prospect." error={form.errors.reason}>
                <Textarea
                    id="reject-reason"
                    rows={3}
                    autoFocus
                    maxLength={500}
                    placeholder="Needs fuel pump integration, which SSPOS does not do."
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                    aria-invalid={!!form.errors.reason}
                />
            </FormField>
            <div className="grid gap-2">
                <label htmlFor="reject-notify" className={lead.email ? 'flex cursor-pointer items-start gap-3' : 'flex items-start gap-3 opacity-60'}>
                    <Checkbox
                        id="reject-notify"
                        checked={form.data.notify}
                        disabled={!lead.email}
                        onCheckedChange={(checked) => form.setData('notify', checked === true)}
                        className="mt-0.5"
                    />
                    <span className="grid gap-0.5">
                        <span className="text-sm font-medium">Email {lead.contactName} a short, polite note</span>
                        <span className="text-muted-foreground text-sm">
                            {lead.email
                                ? `“We are not able to offer a free trial at the moment…” to ${lead.email}. No reason is given.`
                                : 'This lead has no email address.'}
                        </span>
                    </span>
                </label>
                {form.errors.notify && <p className="text-danger-foreground text-[13px]">{form.errors.notify}</p>}
            </div>
        </DialogForm>
    );
}
