import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Minus, MonitorSmartphone, Plus } from 'lucide-react';

interface TillCountPickerProps {
    value: number;
    min?: number;
    max: number;
    onChange: (value: number) => void;
    error?: string;
    id?: string;
}

/** Number of tills with a preview of the names they get ("Till 1" is the main till). */
export function TillCountPicker({ value, min = 1, max, onChange, error, id = 'tills' }: TillCountPickerProps) {
    const clamp = (next: number) => Math.min(max, Math.max(min, Number.isFinite(next) ? Math.round(next) : min));

    return (
        <div className="grid gap-3">
            <Label htmlFor={id}>Number of tills</Label>
            <div className="flex items-center gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    onClick={() => onChange(clamp(value - 1))}
                    disabled={value <= min}
                    aria-label="One fewer till"
                >
                    <Minus />
                </Button>
                <Input
                    id={id}
                    type="number"
                    inputMode="numeric"
                    min={min}
                    max={max}
                    value={value}
                    onChange={(e) => onChange(clamp(Number(e.target.value)))}
                    className="w-20 text-center tabular-nums"
                    aria-invalid={!!error}
                    aria-describedby={`${id}-hint`}
                />
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    onClick={() => onChange(clamp(value + 1))}
                    disabled={value >= max}
                    aria-label="One more till"
                >
                    <Plus />
                </Button>
                <span id={`${id}-hint`} className="text-muted-foreground text-sm">
                    {min} to {max}
                </span>
            </div>
            <InputError message={error} />
            {value > 0 && (
                <ul className="flex flex-wrap gap-2" aria-label="Tills to be created">
                    {Array.from({ length: value }, (_, index) => (
                        <li key={index} className="bg-muted/40 inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1 text-sm">
                            <MonitorSmartphone className="text-muted-foreground size-3.5" aria-hidden />
                            Till {index + 1}
                            {index === 0 && <span className="text-primary text-xs font-medium">Main</span>}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
