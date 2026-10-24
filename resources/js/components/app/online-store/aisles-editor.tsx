import { showToast } from '@/components/shared/toaster';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ImageIcon, LoaderCircle, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { ToggleSwitch } from './toggle-switch';
import { type BuilderProps, imageProblem, type StoreAisle } from './types';

type Row = { id: string; name: string; hidden: boolean };

const SOURCES = [
    { value: 'auto', label: 'Automatic (the better of the two)' },
    { value: 'departments', label: 'Till departments' },
    { value: 'categories', label: 'Till categories' },
];

/** One aisle's photo: upload now (it saves on its own) or remove. */
function AislePhoto({ aisle, disabled }: { aisle: StoreAisle; disabled: boolean }) {
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const options = { preserveScroll: true, preserveState: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };
    const upload = (file: File | undefined) => {
        const problem = file ? imageProblem(file) : null;
        if (!file || problem) {
            if (problem) showToast(problem, 'error');
            return;
        }
        router.post(route('app.online-store.aisles.photo', aisle.id), { photo: file }, { ...options, forceFormData: true });
    };

    return (
        <div className="flex items-center gap-1.5">
            <span className="bg-muted flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-md border">
                {aisle.photoUrl ? <img src={aisle.photoUrl} alt="" className="size-full object-cover" /> : <ImageIcon className="text-muted-foreground size-4" />}
            </span>
            <input
                ref={input}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                className="sr-only"
                tabIndex={-1}
                onChange={(e) => upload(e.target.files?.[0])}
            />
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-8"
                disabled={disabled || busy}
                aria-label={`${aisle.photoUrl ? 'Replace' : 'Add'} the photo for ${aisle.name}`}
                onClick={() => input.current?.click()}
            >
                {busy ? <LoaderCircle className="animate-spin" /> : <Upload />}
            </Button>
            {aisle.photoUrl && (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    disabled={disabled || busy}
                    aria-label={`Remove the photo for ${aisle.name}`}
                    onClick={() => router.delete(route('app.online-store.aisles.photo.remove', aisle.id), options)}
                >
                    <Trash2 />
                </Button>
            )}
        </div>
    );
}

/**
 * OS-11: the store's aisles. Built from the till's departments or categories (never a "no department" bucket); the
 * shop puts them in order, hides some, gives a display name and a photo. Saved on its own ("Save aisles"); photos
 * save as soon as they are chosen.
 */
export function AislesEditor({ props, disabled }: { props: Omit<BuilderProps, 'store'>; disabled: boolean }) {
    const { aisles, aisleSource, aisleSourceUsed, aisleNameMax } = props;
    const form = useForm<{ source: string; aisles: Row[] }>({
        source: aisleSource,
        aisles: aisles.map((a) => ({ id: a.id, name: a.customName ?? '', hidden: a.hidden })),
    });
    const byId = new Map(aisles.map((a) => [a.id, a]));
    const locked = disabled || form.processing;
    const errors = form.errors as Record<string, string | undefined>;
    const firstError = Object.entries(errors).find(([key]) => key.startsWith('aisles'))?.[1];
    const move = (index: number, step: number) => {
        const next = [...form.data.aisles];
        const [row] = next.splice(index, 1);
        next.splice(index + step, 0, row);
        form.setData('aisles', next);
    };
    const update = (index: number, change: Partial<Row>) => form.setData('aisles', form.data.aisles.map((r, i) => (i === index ? { ...r, ...change } : r)));
    const save = () => {
        form.transform((data) => ({ source: data.source === 'auto' ? null : data.source, aisles: data.aisles }));
        form.put(route('app.online-store.aisles.update'), { preserveScroll: true, onSuccess: () => form.setDefaults() });
    };

    return (
        <div className="grid gap-3">
            <div className="flex flex-col gap-2">
                <p className="text-muted-foreground text-sm">
                    {aisleSourceUsed ? `Showing your till ${aisleSourceUsed}.` : 'Add a collection shop to see your aisles.'} Aisles with nothing in
                    stock are hidden from customers.
                </p>
                <Select value={form.data.source} onValueChange={(v) => form.setData('source', v)} disabled={locked}>
                    <SelectTrigger className="w-full sm:max-w-xs" aria-label="Build aisles from">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {SOURCES.map((s) => (
                            <SelectItem key={s.value} value={s.value}>
                                {s.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            {form.data.aisles.length > 0 && (
                <ol className="divide-border grid divide-y rounded-lg border" aria-label="Aisles, in order">
                    {form.data.aisles.map((row, index) => {
                        const aisle = byId.get(row.id);
                        if (!aisle) return null;
                        const label = row.name.trim() || aisle.name;

                        return (
                            <li key={row.id} className={cn('flex flex-wrap items-center gap-3 px-3 py-2.5', row.hidden && 'bg-muted/40')}>
                                <span className="text-muted-foreground w-5 text-center text-xs tabular-nums">{index + 1}</span>
                                <div className="grid min-w-0 flex-1 basis-48 gap-1">
                                    <Input
                                        value={row.name}
                                        placeholder={aisle.name}
                                        maxLength={aisleNameMax}
                                        disabled={locked}
                                        aria-label={`Display name for ${aisle.tillName}`}
                                        className="h-8"
                                        onChange={(e) => update(index, { name: e.target.value })}
                                    />
                                    <p className="text-muted-foreground truncate text-xs">
                                        On the till: {aisle.tillName} · {aisle.count} in stock
                                    </p>
                                </div>
                                <AislePhoto aisle={aisle} disabled={disabled} />
                                <div className="flex items-center gap-1">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-8"
                                        aria-label={`Move ${label} up`}
                                        disabled={locked || index === 0}
                                        onClick={() => move(index, -1)}
                                    >
                                        <ArrowUp />
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="size-8"
                                        aria-label={`Move ${label} down`}
                                        disabled={locked || index === form.data.aisles.length - 1}
                                        onClick={() => move(index, 1)}
                                    >
                                        <ArrowDown />
                                    </Button>
                                </div>
                                <ToggleSwitch
                                    checked={!row.hidden}
                                    label={`Show ${label}`}
                                    disabled={locked}
                                    onCheckedChange={(on) => update(index, { hidden: !on })}
                                />
                            </li>
                        );
                    })}
                </ol>
            )}
            {firstError && <p className="text-danger-foreground text-[13px]">{firstError}</p>}
            {!disabled && (
                <div className="flex justify-end gap-2">
                    {form.isDirty && (
                        <Button type="button" variant="outline" size="sm" disabled={form.processing} onClick={() => form.reset()}>
                            Discard
                        </Button>
                    )}
                    <Button type="button" size="sm" disabled={form.processing || !form.isDirty} onClick={save}>
                        {form.processing && <LoaderCircle className="animate-spin" />}
                        Save aisles
                    </Button>
                </div>
            )}
        </div>
    );
}
