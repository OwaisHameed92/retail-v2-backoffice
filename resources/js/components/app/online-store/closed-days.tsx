import { FormField } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/country';
import { router, useForm } from '@inertiajs/react';
import { CalendarX2, LoaderCircle, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { type ShopClosure } from './types';

/**
 * Audit OSA-13: a shop's closed days and special hours (bank holidays, Christmas, a staff shortage). They apply to
 * collection and delivery slots; customers see "Closed" with the reason on that day.
 */
export function ClosedDays({ branchId, closures, disabled }: { branchId: string; closures: ShopClosure[]; disabled: boolean }) {
    const [adding, setAdding] = useState(false);
    const form = useForm({ date: '', all_day: true as boolean, opens_at: '10:00', closes_at: '14:00', reason: '' });
    const errors = form.errors as Record<string, string | undefined>;
    const id = (name: string) => `${branchId}-closure-${name}`;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('app.online-store.closures.store', branchId), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setAdding(false);
            },
        });
    };

    return (
        <div className="grid gap-3 rounded-lg border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="flex items-center gap-2 text-sm font-semibold">
                    <CalendarX2 className="text-muted-foreground size-4" aria-hidden />
                    Closed days and special hours
                </p>
                {!adding && (
                    <Button type="button" variant="outline" size="sm" disabled={disabled} onClick={() => setAdding(true)}>
                        <Plus />
                        Add a day
                    </Button>
                )}
            </div>
            {closures.length === 0 && !adding && <p className="text-muted-foreground text-sm">None. Add bank holidays and other days off here.</p>}
            {closures.length > 0 && (
                <ul className="divide-border grid divide-y rounded-md border">
                    {closures.map((c) => (
                        <li key={c.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                            <span>
                                <span className="font-medium">{formatDate(c.date, { weekday: 'short' })}</span>
                                <span className="text-muted-foreground">
                                    {' · '}
                                    {c.opensAt ? `Open ${c.opensAt} to ${c.closesAt}` : 'Closed all day'}
                                    {c.reason ? ` · ${c.reason}` : ''}
                                </span>
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                disabled={disabled}
                                aria-label={`Remove ${c.date}`}
                                onClick={() => router.delete(route('app.online-store.closures.destroy', [branchId, c.id]), { preserveScroll: true })}
                            >
                                <Trash2 />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            {adding && (
                <form onSubmit={submit} className="grid gap-3">
                    <div className="grid gap-3 sm:grid-cols-[10rem_1fr]">
                        <FormField id={id('date')} label="Date" error={errors.date}>
                            <Input
                                id={id('date')}
                                type="date"
                                className="h-9"
                                value={form.data.date}
                                aria-invalid={!!errors.date}
                                onChange={(e) => form.setData('date', e.target.value)}
                            />
                        </FormField>
                        <FormField id={id('reason')} label="Reason customers see" optional error={errors.reason}>
                            <Input
                                id={id('reason')}
                                className="h-9"
                                maxLength={120}
                                placeholder="Christmas Day"
                                value={form.data.reason}
                                onChange={(e) => form.setData('reason', e.target.value)}
                            />
                        </FormField>
                    </div>
                    <div className="flex flex-wrap items-center gap-3">
                        <Checkbox id={id('allday')} checked={form.data.all_day} onCheckedChange={(on) => form.setData('all_day', on === true)} />
                        <Label htmlFor={id('allday')} className="font-normal">
                            Closed all day
                        </Label>
                        {!form.data.all_day && (
                            <span className="flex items-center gap-2">
                                <Input
                                    type="time"
                                    className="h-9 w-32"
                                    aria-label="Open from"
                                    value={form.data.opens_at}
                                    onChange={(e) => form.setData('opens_at', e.target.value)}
                                />
                                <span className="text-muted-foreground text-sm">to</span>
                                <Input
                                    type="time"
                                    className="h-9 w-32"
                                    aria-label="Open until"
                                    value={form.data.closes_at}
                                    onChange={(e) => form.setData('closes_at', e.target.value)}
                                />
                            </span>
                        )}
                    </div>
                    {(errors['hours.open'] ?? errors['hours.close'] ?? errors.branch_id) && (
                        <p className="text-danger-foreground text-[13px]">{errors['hours.open'] ?? errors['hours.close'] ?? errors.branch_id}</p>
                    )}
                    <div className="flex gap-2">
                        <Button type="submit" size="sm" disabled={form.processing}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Save day
                        </Button>
                        <Button type="button" size="sm" variant="outline" onClick={() => setAdding(false)}>
                            Cancel
                        </Button>
                    </div>
                </form>
            )}
        </div>
    );
}
