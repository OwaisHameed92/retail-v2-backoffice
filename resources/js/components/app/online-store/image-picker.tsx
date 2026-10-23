import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { ImageIcon, RotateCcw, Trash2, Upload } from 'lucide-react';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { imageProblem } from './types';

interface ImagePickerProps {
    /** The saved picture, or null. */
    current: string | null;
    /** The new file picked (not saved yet). */
    file: File | null;
    /** The saved picture is to be removed on save. */
    removed: boolean;
    onPick: (file: File | null) => void;
    onRemove: (removed: boolean) => void;
    /** Shown when there is no picture (e.g. the template's photo for the banner). */
    fallback?: string;
    fallbackNote?: ReactNode;
    label: string;
    error?: string;
    disabled?: boolean;
    shape?: 'square' | 'wide';
    help?: ReactNode;
}

/** A picture field: preview, choose or replace (JPG, PNG, WebP up to 4 MB, checked here and on the server), remove, undo. */
export function ImagePicker({
    current,
    file,
    removed,
    onPick,
    onRemove,
    fallback,
    fallbackNote,
    label,
    error,
    disabled,
    shape = 'square',
    help,
}: ImagePickerProps) {
    const input = useRef<HTMLInputElement>(null);
    const id = useId();
    const [preview, setPreview] = useState<string | null>(null);
    const [problem, setProblem] = useState<string | null>(null);

    useEffect(() => {
        if (!file) {
            setPreview(null);

            return;
        }
        const url = URL.createObjectURL(file);
        setPreview(url);

        return () => URL.revokeObjectURL(url);
    }, [file]);

    const shown = preview ?? (removed ? null : current);
    const src = shown ?? fallback ?? null;
    const message = problem ?? error;

    return (
        <div className="grid gap-2">
            <div className={cn('flex gap-4', shape === 'wide' ? 'flex-col' : 'items-center')}>
                <div
                    className={cn(
                        'bg-muted relative flex shrink-0 items-center justify-center overflow-hidden border',
                        shape === 'wide' ? 'aspect-[16/6] w-full rounded-lg' : 'size-20 rounded-xl',
                    )}
                >
                    {src ? (
                        <img
                            src={src}
                            alt={shown ? label : ''}
                            className={cn('size-full', shape === 'wide' ? 'object-cover' : 'object-contain p-1.5')}
                        />
                    ) : (
                        <ImageIcon className="text-muted-foreground size-6" aria-hidden />
                    )}
                    {!shown && fallback && fallbackNote && (
                        <span className="bg-card/90 text-foreground absolute bottom-2 left-2 rounded-md px-2 py-0.5 text-xs font-medium shadow-sm">
                            {fallbackNote}
                        </span>
                    )}
                </div>
                <div className="grid gap-2">
                    <div className="flex flex-wrap gap-2">
                        <input
                            ref={input}
                            id={id}
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            className="sr-only"
                            disabled={disabled}
                            aria-label={label}
                            aria-invalid={!!message}
                            onChange={(e) => {
                                const picked = e.target.files?.[0] ?? null;
                                e.target.value = '';
                                if (!picked) {
                                    return;
                                }
                                const issue = imageProblem(picked);
                                setProblem(issue);
                                if (!issue) {
                                    onPick(picked);
                                    onRemove(false);
                                }
                            }}
                        />
                        <Button type="button" variant="outline" size="sm" disabled={disabled} onClick={() => input.current?.click()}>
                            <Upload />
                            {shown ? 'Replace' : 'Upload'}
                        </Button>
                        {file && (
                            <Button type="button" variant="ghost" size="sm" disabled={disabled} onClick={() => onPick(null)}>
                                <RotateCcw />
                                Undo
                            </Button>
                        )}
                        {!file && current && !removed && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="text-destructive"
                                disabled={disabled}
                                onClick={() => onRemove(true)}
                            >
                                <Trash2 />
                                Remove
                            </Button>
                        )}
                        {!file && current && removed && (
                            <Button type="button" variant="ghost" size="sm" disabled={disabled} onClick={() => onRemove(false)}>
                                <RotateCcw />
                                Keep it
                            </Button>
                        )}
                    </div>
                    {message ? (
                        <p className="text-danger-foreground text-[13px] leading-5">{message}</p>
                    ) : (
                        <p className="text-muted-foreground text-[13px] leading-5">{help ?? 'JPG, PNG or WebP, up to 4 MB.'}</p>
                    )}
                </div>
            </div>
        </div>
    );
}
