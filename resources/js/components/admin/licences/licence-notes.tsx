import { Textarea } from '@/components/admin/tenants/field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

/** Staff-only notes on a licence (never sent to the till or the customer). */
export function LicenceNotes({ licenceId, notes, canEdit }: { licenceId: string; notes: string | null; canEdit: boolean }) {
    const { data, setData, put, processing, errors, isDirty, setDefaults } = useForm({ notes: notes ?? '' });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        put(route('admin.licences.notes', licenceId), { preserveScroll: true, onSuccess: () => setDefaults() });
    };

    return (
        <Card>
            <CardHeader className="pb-4">
                <h2 className="text-base font-semibold">Notes</h2>
                <p className="text-muted-foreground text-sm">Only Switch & Save staff see these.</p>
            </CardHeader>
            <CardContent>
                {canEdit ? (
                    <form onSubmit={submit} className="grid gap-3">
                        <label htmlFor="licence-notes" className="sr-only">
                            Notes
                        </label>
                        <Textarea
                            id="licence-notes"
                            rows={4}
                            maxLength={5000}
                            value={data.notes}
                            placeholder="For example: back office PC, replaced in March after a power cut"
                            onChange={(event) => setData('notes', event.target.value)}
                            aria-invalid={!!errors.notes}
                        />
                        <InputError message={errors.notes} />
                        <div className="flex justify-end">
                            <Button type="submit" size="sm" disabled={processing || !isDirty}>
                                {processing && <LoaderCircle className="size-4 animate-spin" />}
                                Save notes
                            </Button>
                        </div>
                    </form>
                ) : (
                    <p className="text-sm whitespace-pre-line">{notes ?? <span className="text-muted-foreground">No notes.</span>}</p>
                )}
            </CardContent>
        </Card>
    );
}
