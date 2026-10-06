import { formatCalendarDate, renewalPreview, shopToday } from '@/components/admin/licences/format';
import { Field } from '@/components/admin/tenants/field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { timeZone } from '@/lib/country';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { CalendarCheck, LoaderCircle } from 'lucide-react';
import { type FormEventHandler, type ReactNode } from 'react';

type Term = 'month' | 'year' | 'until';

interface RenewDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: ReactNode;
    /** POST target: one licence or a tenant's "renew all". */
    url: string;
    /** Current trial end or paid expiry (ISO), used to preview the new date. */
    currentEnd: string | null;
    confirmLabel?: string;
}

const terms: { value: Term; title: string; body: string }[] = [
    { value: 'month', title: '1 month', body: 'From the current end date, or today if it has passed.' },
    { value: 'year', title: '1 year', body: 'From the current end date, or today if it has passed.' },
    { value: 'until', title: 'Until a date', body: 'Runs to the end of the day you choose.' },
];

/** Renew one licence or all of a tenant's licences: +1 month, +1 year or until a date, with an optional email. */
export function RenewDialog(props: RenewDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <RenewDialogBody {...props} />}
        </Dialog>
    );
}

function RenewDialogBody({ onOpenChange, title, description, url, currentEnd, confirmLabel = 'Renew' }: RenewDialogProps) {
    const { data, setData, post, processing, errors } = useForm<{ term: Term; until: string; notify: boolean }>({
        term: 'month',
        until: '',
        notify: true,
    });

    const preview = data.term === 'until' ? data.until || null : renewalPreview(data.term, currentEnd);
    const today = shopToday();

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        post(url, { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <DialogContent onInteractOutside={(event) => processing && event.preventDefault()}>
            <form onSubmit={submit} className="grid gap-5" noValidate>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <fieldset className="grid gap-2">
                    <legend className="mb-2 text-sm font-medium">Renew for</legend>
                    {terms.map((option) => (
                        <label
                            key={option.value}
                            className={cn(
                                'focus-within:ring-ring flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors focus-within:ring-2',
                                data.term === option.value ? 'border-primary bg-accent' : 'hover:bg-muted/50',
                            )}
                        >
                            <input
                                type="radio"
                                name="term"
                                value={option.value}
                                checked={data.term === option.value}
                                onChange={() => setData('term', option.value)}
                                className="accent-primary mt-1"
                            />
                            <span className="grid gap-0.5">
                                <span className="text-sm font-medium">{option.title}</span>
                                <span className="text-muted-foreground text-sm">{option.body}</span>
                            </span>
                        </label>
                    ))}
                </fieldset>

                {data.term === 'until' && (
                    <Field id="renew-until" label="Valid until" error={errors.until} hint={`${timeZone()}. The licence runs to 23:59 that day.`}>
                        <Input
                            id="renew-until"
                            type="date"
                            min={today}
                            value={data.until}
                            onChange={(event) => setData('until', event.target.value)}
                            aria-invalid={!!errors.until}
                            autoFocus
                        />
                    </Field>
                )}

                {preview && preview > today && (
                    <p className="bg-success-soft text-success-foreground flex items-center gap-2 rounded-lg px-3 py-2 text-sm">
                        <CalendarCheck className="size-4 shrink-0" aria-hidden />
                        New expiry: <span className="font-medium tabular-nums">{formatCalendarDate(preview)}</span>
                    </p>
                )}
                {errors.term && <p className="text-destructive text-sm">{errors.term}</p>}

                <div className="flex items-start gap-3">
                    <Checkbox
                        id="renew-notify"
                        checked={data.notify}
                        onCheckedChange={(checked) => setData('notify', checked === true)}
                        className="mt-0.5"
                    />
                    <div className="grid gap-1">
                        <Label htmlFor="renew-notify">Email the owner</Label>
                        <p className="text-muted-foreground text-sm">Sends the “licences renewed” email with the new expiry date.</p>
                    </div>
                </div>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing || (data.term === 'until' && !data.until)}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
