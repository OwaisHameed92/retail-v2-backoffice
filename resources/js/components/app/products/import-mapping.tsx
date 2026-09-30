import { SectionCard } from '@/components/shared/section-card';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useForm } from '@inertiajs/react';
import { LoaderCircle, SearchCheck } from 'lucide-react';
import { type FormEventHandler } from 'react';
import { type ImportDetail, type ImportField } from './import-types';

/** Match each CSV column to a product field (or leave it out), then check the whole file. */
export function ImportMapping({ detail, fields }: { detail: ImportDetail; fields: ImportField[] }) {
    const { data, setData, post, processing, errors } = useForm<{ mapping: Record<string, number | null> }>({ mapping: { ...detail.mapping } });
    const fieldFor = (column: number) => Object.entries(data.mapping).find(([, index]) => index === column)?.[0] ?? 'skip';
    const findable = data.mapping.barcode !== undefined && data.mapping.barcode !== null ? true : data.mapping.sku !== undefined && data.mapping.sku !== null;

    const choose = (column: number, field: string) => {
        const next: Record<string, number | null> = {};
        for (const [key, index] of Object.entries(data.mapping)) {
            if (index !== column && key !== field && index !== null) next[key] = index;
        }
        if (field !== 'skip') next[field] = column;
        setData('mapping', next);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('app.products.imports.preview', detail.id), { preserveScroll: true });
    };

    return (
        <SectionCard
            title="Match your columns"
            description="We guessed from the column names. Change any that are wrong; columns set to “Don't import” are ignored."
            flush
        >
            <form onSubmit={submit}>
                <ul className="divide-y">
                    {detail.headers.map((header, column) => {
                        const field = fieldFor(column);
                        const help = fields.find((f) => f.value === field)?.help;

                        return (
                            <li key={column} className="grid grid-cols-1 gap-2 px-5 py-3 sm:grid-cols-[minmax(0,1fr)_16rem] sm:items-center">
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-medium">{header || `Column ${column + 1}`}</p>
                                    {help && <p className="text-muted-foreground text-[13px]">{help}</p>}
                                </div>
                                <Select value={field} onValueChange={(value) => choose(column, value)}>
                                    <SelectTrigger aria-label={`Import ${header || `column ${column + 1}`} as`} className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="skip">Don't import</SelectItem>
                                        {fields.map((option) => (
                                            <SelectItem key={option.value} value={option.value}>
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </li>
                        );
                    })}
                </ul>
                <div className="flex flex-wrap items-center justify-end gap-3 border-t px-5 py-4">
                    {(errors.mapping || !findable) && (
                        <p className="text-danger-foreground mr-auto text-[13px]">{errors.mapping ?? 'Match the barcode or the product code column: it is how products are found.'}</p>
                    )}
                    <Button type="submit" disabled={processing || !findable}>
                        {processing ? <LoaderCircle className="size-4 animate-spin" aria-hidden /> : <SearchCheck />}
                        {detail.previewedAt ? 'Check the file again' : 'Check the file'}
                    </Button>
                </div>
            </form>
        </SectionCard>
    );
}
