import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { ToggleSwitch } from './toggle-switch';
import { type StoreSectionValue } from './types';

interface SectionsEditorProps {
    value: StoreSectionValue[];
    options: { key: string; label: string; hint: string }[];
    onChange: (value: StoreSectionValue[]) => void;
    disabled?: boolean;
}

/** Home page sections: on or off, moved up or down (buttons, so it works by keyboard and on phones). */
export function SectionsEditor({ value, options, onChange, disabled }: SectionsEditorProps) {
    const info = (key: string) => options.find((o) => o.key === key);
    const move = (index: number, step: number) => {
        const next = [...value];
        const [item] = next.splice(index, 1);
        next.splice(index + step, 0, item);
        onChange(next);
    };

    return (
        <ol className="divide-border grid divide-y rounded-lg border" aria-label="Home page sections, in order">
            {value.map((section, index) => {
                const meta = info(section.key);
                const label = meta?.label ?? section.key;

                return (
                    <li key={section.key} className={cn('flex items-center gap-3 px-3 py-2.5', !section.enabled && 'bg-muted/40')}>
                        <span className="text-muted-foreground w-5 text-center text-xs tabular-nums">{index + 1}</span>
                        <div className="min-w-0 flex-1">
                            <p className={cn('text-sm font-medium', !section.enabled && 'text-muted-foreground')}>{label}</p>
                            {meta?.hint && <p className="text-muted-foreground truncate text-xs">{meta.hint}</p>}
                        </div>
                        <div className="flex items-center gap-1">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-8"
                                aria-label={`Move ${label} up`}
                                disabled={disabled || index === 0}
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
                                disabled={disabled || index === value.length - 1}
                                onClick={() => move(index, 1)}
                            >
                                <ArrowDown />
                            </Button>
                        </div>
                        <ToggleSwitch
                            checked={section.enabled}
                            label={`Show ${label}`}
                            disabled={disabled}
                            onCheckedChange={(on) => onChange(value.map((s, i) => (i === index ? { ...s, enabled: on } : s)))}
                        />
                    </li>
                );
            })}
        </ol>
    );
}
