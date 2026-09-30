import { FormField } from '@/components/shared/form-section';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { LoaderCircle, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { CLASSES, ClassPill } from './format';
import { type MedicineClass, type MedicinesProps } from './types';

export interface MedicineTarget {
    productId: string;
    product: string;
    class: MedicineClass | null;
    note: string | null;
}

interface MedicineDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The product being edited; null = pick one first. */
    target: MedicineTarget | null;
    candidates: MedicinesProps['candidates'];
}

/** Set a product's medicine class: find the product (new), then the class and an optional note for the till. */
export function MedicineDialog({ open, onOpenChange, target, candidates }: MedicineDialogProps) {
    const [picked, setPicked] = useState<MedicineTarget | null>(target);
    const [find, setFind] = useState('');
    const form = useForm<{ class: MedicineClass | ''; note: string }>({ class: target?.class ?? '', note: target?.note ?? '' });

    useEffect(() => {
        if (open) {
            setPicked(target);
            setFind('');
            form.setData({ class: target?.class ?? '', note: target?.note ?? '' });
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, target]);

    useEffect(() => {
        if (!open || picked) {
            return;
        }
        const timer = setTimeout(() => router.reload({ only: ['candidates'], data: { find: find.trim() || undefined } }), 250);

        return () => clearTimeout(timer);
    }, [find, open, picked]);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!picked) {
            return;
        }
        form.put(route('app.pharmacy.medicines.save', picked.productId), { preserveScroll: true, onSuccess: () => onOpenChange(false) });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-5">
                    <DialogHeader>
                        <DialogTitle>{target ? `Medicine class · ${target.product}` : 'Classify a product'}</DialogTitle>
                        <DialogDescription>Every shop&apos;s tills get the class at their next sync and apply it at the till.</DialogDescription>
                    </DialogHeader>

                    {!picked ? (
                        <div className="grid gap-2">
                            <div className="relative">
                                <Search className="text-muted-foreground absolute top-2.5 left-2.5 size-4" />
                                <Input
                                    autoFocus
                                    className="pl-8"
                                    placeholder="Search products by name or code"
                                    value={find}
                                    onChange={(e) => setFind(e.target.value)}
                                    aria-label="Search products"
                                />
                            </div>
                            <div className="divide-border max-h-72 divide-y overflow-y-auto rounded-lg border">
                                {find.trim().length < 2 ? (
                                    <p className="text-muted-foreground p-3 text-sm">Type at least two letters.</p>
                                ) : candidates.length === 0 ? (
                                    <p className="text-muted-foreground p-3 text-sm">No products match.</p>
                                ) : (
                                    candidates.map((c) => (
                                        <button
                                            type="button"
                                            key={c.id}
                                            className="hover:bg-muted/60 flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm"
                                            onClick={() => {
                                                setPicked({ productId: c.id, product: c.name, class: c.class, note: null });
                                                form.setData({ class: c.class ?? '', note: '' });
                                            }}
                                        >
                                            <span className="grid leading-5">
                                                <span className="font-medium">{c.name}</span>
                                                {c.sku && <span className="text-muted-foreground text-xs">{c.sku}</span>}
                                            </span>
                                            <ClassPill value={c.class} />
                                        </button>
                                    ))
                                )}
                            </div>
                        </div>
                    ) : (
                        <>
                            {!target && (
                                <div className="bg-muted/50 flex items-center justify-between rounded-lg px-3 py-2 text-sm">
                                    <span className="font-medium">{picked.product}</span>
                                    <Button type="button" variant="ghost" size="sm" onClick={() => setPicked(null)}>
                                        Change
                                    </Button>
                                </div>
                            )}
                            <FormField id="medicine-class" label="Class" error={form.errors.class}>
                                <Select value={form.data.class} onValueChange={(value) => form.setData('class', value as MedicineClass)}>
                                    <SelectTrigger id="medicine-class" aria-invalid={!!form.errors.class}>
                                        <SelectValue placeholder="Choose a class" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(Object.keys(CLASSES) as MedicineClass[]).map((c) => (
                                            <SelectItem key={c} value={c}>
                                                {CLASSES[c].label} ({CLASSES[c].short}) · {CLASSES[c].help}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <FormField id="medicine-note" label="Note for the till" optional error={form.errors.note}>
                                <Textarea
                                    id="medicine-note"
                                    rows={3}
                                    maxLength={500}
                                    placeholder="For example: maximum two packs per sale"
                                    value={form.data.note}
                                    onChange={(e) => form.setData('note', e.target.value)}
                                />
                            </FormField>
                            {'productId' in form.errors && <p className="text-destructive text-sm">{(form.errors as Record<string, string>).productId}</p>}
                        </>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={!picked || !form.data.class || form.processing} className={cn(!picked && 'opacity-60')}>
                            {form.processing && <LoaderCircle className="animate-spin" />}
                            Save class
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
