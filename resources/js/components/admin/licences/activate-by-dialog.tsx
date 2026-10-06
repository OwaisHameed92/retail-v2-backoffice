import { FormField } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { timeZoneLabel, zonedDateFormat } from '@/lib/country';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface ActivateByDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    licenceId: string;
    keyLast4: string;
    /** Current activate-by date (ISO), if any. */
    activateBy: string | null;
}

/** Shop-time calendar date `days` from now, as YYYY-MM-DD. */
function shopDate(days: number): string {
    const date = new Date(Date.now() + days * 86_400_000);

    return zonedDateFormat('en-CA', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(date);
}

/** Moves an unused key's activate-by date (module 1.11). After it, the till gets "key expired". */
export function ActivateByDialog(props: ActivateByDialogProps) {
    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            {props.open && <ActivateByDialogBody {...props} />}
        </Dialog>
    );
}

function ActivateByDialogBody({ onOpenChange, licenceId, keyLast4 }: ActivateByDialogProps) {
    const { data, setData, put, processing, errors } = useForm({ activate_by: shopDate(30) });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('admin.licences.activate-by', licenceId), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <DialogContent className="sm:max-w-md">
            <form onSubmit={submit} className="grid gap-6" noValidate>
                <DialogHeader>
                    <DialogTitle>Extend the activate-by date?</DialogTitle>
                    <DialogDescription>
                        Key …{keyLast4} can be activated on a till until the end of this day ({timeZoneLabel()} time). After it the till is told the key has expired.
                    </DialogDescription>
                </DialogHeader>
                <FormField id="activate_by" label="Activate by" error={errors.activate_by}>
                    <Input
                        id="activate_by"
                        type="date"
                        min={shopDate(0)}
                        value={data.activate_by}
                        onChange={(e) => setData('activate_by', e.target.value)}
                        aria-invalid={!!errors.activate_by}
                        className="max-w-48"
                    />
                </FormField>
                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing || data.activate_by === ''}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Save date
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    );
}
