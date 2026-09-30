import { type FeatureOption } from '@/components/admin/plans/types';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';

interface PlanFeaturePickerProps {
    options: FeatureOption[];
    value: string[];
    onChange: (value: string[]) => void;
    error?: string;
}

/** Checklist of product features with descriptions. Keeps the selection in the enum's order. */
export function PlanFeaturePicker({ options, value, onChange, error }: PlanFeaturePickerProps) {
    const selected = new Set(value);
    const ordered = (next: Set<string>) => options.map((option) => option.value).filter((v) => next.has(v));

    const toggle = (feature: string, checked: boolean) => {
        const next = new Set(selected);
        if (checked) {
            next.add(feature);
        } else {
            next.delete(feature);
        }
        onChange(ordered(next));
    };

    return (
        <fieldset className="grid gap-3" aria-describedby={error ? 'features-error' : undefined}>
            <legend className="sr-only">Features</legend>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-muted-foreground text-sm tabular-nums">
                    {selected.size} of {options.length} selected
                </p>
                <div className="flex gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => onChange(options.map((o) => o.value))}
                        disabled={selected.size === options.length}
                    >
                        Select all
                    </Button>
                    <Button type="button" variant="ghost" size="sm" onClick={() => onChange([])} disabled={selected.size === 0}>
                        Clear
                    </Button>
                </div>
            </div>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {options.map((option) => {
                    const id = `feature-${option.value}`;
                    const checked = selected.has(option.value);

                    return (
                        <label
                            key={option.value}
                            htmlFor={id}
                            className={cn(
                                'hover:bg-muted/50 flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors',
                                checked && 'border-primary/40 bg-accent/60 hover:bg-accent',
                            )}
                        >
                            <Checkbox
                                id={id}
                                checked={checked}
                                onCheckedChange={(state) => toggle(option.value, state === true)}
                                className="mt-0.5"
                            />
                            <span className="grid gap-0.5">
                                <span className="text-sm font-medium">{option.label}</span>
                                <span className="text-muted-foreground text-sm">{option.description}</span>
                            </span>
                        </label>
                    );
                })}
            </div>
            {error && (
                <p id="features-error" className="text-destructive text-sm">
                    {error}
                </p>
            )}
        </fieldset>
    );
}
