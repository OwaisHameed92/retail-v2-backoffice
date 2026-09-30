import { FormField } from '@/components/shared/form-section';
import { StatusBadge } from '@/components/shared/status-badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';
import { type RecallDetail } from './types';

export function RecallStatus({ status }: { status: 'open' | 'closed' | null }) {
    return (
        <StatusBadge
            status={status ?? 'unknown'}
            label={status === 'open' ? 'Open' : status === 'closed' ? 'Closed' : 'Unknown'}
            tones={{ open: 'danger', closed: 'neutral' }}
        />
    );
}

/** Close a recall (how much went back to the supplier, a closing note) or reopen it. */
export function RecallStatusDialog({ recall, onClose }: { recall: RecallDetail; onClose: () => void }) {
    const closing = recall.status !== 'closed';
    const { data, setData, put, processing, errors } = useForm({ status: closing ? 'closed' : 'open', returned_qty: '', note: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(route('app.compliance.recalls.status', recall.id), { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{closing ? 'Close this recall?' : 'Reopen this recall?'}</DialogTitle>
                        <DialogDescription>
                            {closing
                                ? 'Close it once every shop has withdrawn the stock. The tills see it as closed at their next sync.'
                                : 'The tills see it as open again at their next sync.'}
                        </DialogDescription>
                    </DialogHeader>
                    {closing && (
                        <FormField id="recall-returned" label="Quantity returned to the supplier" optional error={errors.returned_qty}>
                            <Input
                                id="recall-returned"
                                inputMode="decimal"
                                value={data.returned_qty}
                                onChange={(e) => setData('returned_qty', e.target.value)}
                            />
                        </FormField>
                    )}
                    <FormField
                        id="recall-status-note"
                        label="Note"
                        optional
                        help="Replaces the note the shops see."
                        error={errors.note ?? errors.status}
                    >
                        <Textarea
                            id="recall-status-note"
                            rows={2}
                            maxLength={2000}
                            value={data.note}
                            onChange={(e) => setData('note', e.target.value)}
                        />
                    </FormField>
                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden />}
                            {closing ? 'Close recall' : 'Reopen recall'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
